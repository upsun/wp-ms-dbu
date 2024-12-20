<?php

namespace WP_CLI\MsDbu;

use Composer\Command\SearchCommand;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI_Command;
use Search_Replace_Command;
use WP_CLI\Utils;
use Cache_Command;

class MsDbuCommand extends WP_CLI_Command {
  /**
   * @var array
   */
  protected array $rawRoutes = [];
  /**
   * @var array
   */
  /**
   * @var array
   */
  /**
   * @var array
   */
  protected array $defaultDomainInfo = [];
  /**
   * @var array
   */
  /**
   * @var array
   */
  protected array $filteredRoutes = [];
  /**
   * @var array
   */
  protected array $sites=[];
  /**
   * @var string
   */
  protected string $defaultReplaceURLFull;
  /**
   * @var string
   */
  protected string $defaultSearchURL;
  /**
   * @var string
   */
  protected string $appName;
  /**
   * @var string
   */
  protected static string $envVarPrefix = "PLATFORM_";
  /**
   * @var string
   */
  protected string $regexSearchPttrn='(%s(?!\.%s))';
  /**
   * @var string
   */
  protected string $tblPrefix = "";
  /**
   * @var array|string[]
   */
  protected array $searchOptionsColumns = [
    'option_value',
    'post_content',
    'post_excerpt',
    'post_content_filtered',
    'meta_value',
  ];
  /**
   * @var array|string[]
   */
  protected array $searchMainColumns = ['domain'];
  /**
   * @var SearchReplacer
   */
  protected SearchReplacer $searchReplacer;
  /**
   * @var string
   */
  protected string $replacePattern = 'wp search-replace \'%s\' %s %s --include-columns=%s --url=%s --verbose';

  /**
   * @var array|string[]
   */
  protected array $mainTables = ['site','blogs'];
  protected array $optionsTables = ['options','posts','postmeta'];
  protected array $associative = ['verbose'=>false,'dry-run'=>false];

  protected bool $subdirectoryType = false;

  /**
   * Updates WordPress multisites in non-production environments on Platform.sh.
   *
   * ## OPTIONS
   *
   * [--routes=<routes>]
   * : JSON object that describes the routes for the environment. Only needed if PLATFORM_ROUTES is not set.
   *
   * [--app-name=<app-name>]
   * : The app name as set in your app configuration. Only needed if PLATFORM_APPLICATION_NAME is not set
   *
   * [--dry-run]
   * : Run the entire search/replace operation and show report, but don’t save changes to the database.
   *
   * [--verbose]
   * : Prints rows to the console as they’re updated.
   *
   * ## EXAMPLES
   *
   *     # Update the database with environment routes
   *     $ wp ms-dbu update
   *     Success: All domains have been updated!
   *
   *
   * @param array $args Indexed array of positional arguments.
   * @param array $assoc_args Associative array of associative arguments.
   * @throws ExitException
   */
  public function __invoke(array $args, array $assoc_args ) {

    $this->setUpRoutesAndDomain($assoc_args);

    //we have to set up the routes and domain data in order to determine if we've already updated.
    if ($this->determineIfAlreadyUpdated()) {
      WP_CLI::log("Multisite already updated with domain info. Skipping...");
      return;
    }

    $this->setUpRemainingValues($assoc_args);
    $this->updateDB();
    $this->flushCache();
  }

  /**
   * Flushes the object cache to make sure we're getting updated, accurate information after making changes
   * Depending on the cache method in use, information from queries may be cached instead of executed. Once we have made
   * changes to the database, we need to flush the cache so new cache can be generated with the updated information
   * @return void
   */
  protected function flushCache(): void {
    $cache = new \Cache_Command();
    WP_CLI::log("Flushing cache now that domains have updated...");
    $cache->flush([],['url'=>parse_url($this->defaultSearchURL, PHP_URL_HOST)]);
    WP_CLI::log("Cache flushed.");
  }

  /**
   * Determines and sets up our routing (urls/domains) information, and App name (needed for determining domain info)
   * @throws ExitException
   */
  protected function setUpRoutesAndDomain(?array $data): void {
    //figure out where we get our route info
    $routes = (isset($data['routes']) && "" !== $data['routes']) ? $data['routes'] : self::getRouteFromEnvVar();
    //save our raw routes data
    $this->setRawRoutes(self::parseRouteJson($routes));
    //save our app name
    $this->setAppName((isset($data['app-name']) && "" !== $data['app-name']) ? $data['app-name'] : self::getEnvVar('APPLICATION_NAME'));
    //get our filtered route data
    $this->setFilteredRoutes();
    $this->setDefaultDomainInfo();
    $this->setDefaultReplaceURL();
    $this->setDefaultSearchURL();
  }

  /**
   * Sets up the remaining info we need (table prefixes, list of sites, flags, multisite type, etc) before we begin
   * @param array|null $data
   * @return void
   */
  protected function setUpRemainingValues(?array $data): void {
    $this->getTablePrefix();
    $this->updateTablesWithPrefix();
    $this->getSites();
    $this->orderFilteredRoutesByDomainLength();
    $this->setFlags($data);
    $this->determineMultisiteType();
  }

  /**
   * Determines which multisite type is in use: (sub|multi)-domain or subdirectory
   * @return void
   */
  protected function determineMultisiteType(): void {
    $this->subdirectoryType = defined('SUBDOMAIN_INSTALL') && false === constant('SUBDOMAIN_INSTALL');
  }

  /**
   * Checks to see if the database has already been updated to the new preview environment URL
   * @return bool
   */
  protected function determineIfAlreadyUpdated(): bool {
    /**
     * we can't do a straight comparison because routes always adds a trailing slash. WordPress may or may not have it
     * depending on how you ask for it. get_option should *not* include it.
     */
    $siteInDb = get_option('siteurl');
    if (false === strpos($this->defaultSearchURL,$siteInDb)) {
      //already updated
      return true;
    } else {
      //we need to update
      return false;
    }
  }

  /**
   * Runs the update process to update all production urls to new preview environment urls
   * @return void
   * @todo @see https://rudrastyh.com/wordpress-multisite/switch_to-blog-performance.html
   */
  protected function updateDB(): void {
    $startTime = microtime(true);

    foreach ($this->filteredRoutes as $urlReplace=>$routeData) {
      $positional = [];
      $associative = $this->associative;

      WP_CLI::log(sprintf("I am going to try and find %s\nAnd replace it with %s", $routeData['production_url'], $urlReplace));

      if (false === $blogID = array_search($routeData['production_url'], array_column($this->sites, 'url','blog_id'), true)) {
        WP_CLI::log(sprintf('I am unable to find a blog id for %s. Skipping.',$routeData['production_url']));
        continue;
      }

      /**
       * Is this a subdirectory-based multisite?
       */


      $domainSearch = parse_url($routeData['production_url'], PHP_URL_HOST);
      $positional[] = $domainSearch;
      $domainReplace = parse_url($urlReplace, PHP_URL_HOST);
      $positional[] = $domainReplace;

      //$targetTables = array_merge($this->tables,$this->processOptionsTables($blogID));
      //First we need to update the site specific tables
      $positionalIndvTable = [...$positional,...$this->processOptionsTables($blogID)];
      ///$searchTables = implode(' ', $targetTables);
      //$searchColumns = implode(' ', $this->searchColumns);
      $associative['include-columns'] = implode(',', $this->searchOptionsColumns);
      $associative['url'] = $routeData['production_url'];
      /**
      * For the primary domain, we want to run it through the whole network, otherwise we end up with a mismatch between
      * wp_blogs and a site's wp_#_options table
       */
      //$network = (isset($routeData['primary']) && $routeData['primary']) ? ' --network' : '';
//      if(isset($routeData['primary']) && $routeData['primary']) {
//        $associative['network'] = true;
//      }

      //$command = sprintf($this->replacePattern, $domainSearch, $domainReplace, $searchTables, $searchColumns, $routeData['production_url']);
      switch_to_blog($blogID);

      $searcherIndvTables=new Search_Replace_Command();
      $searcherIndvTables($positionalIndvTable, $associative);
      restore_current_blog();

      WP_CLI::log(sprintf("Individual site tables updated for %s. Now updating network tables...", $domainSearch));

      /**
       * Set up the positional args we need
       */
      $mainPositional = [];
      /**
       * @todo can we add the positional elements by indexed keys and then resort before handing it over? That way we
       * could add the replace positional element once instead of in each section
       */
      if (!$this->subdirectoryType) {
        WP_CLI::debug("This is a (multi|sub)domain multisite.");
        $mainPositional[] = '(?<!\.)'.preg_quote($domainSearch,'/'); //search position
        $mainPositional[] = $domainReplace; // replace position
        $mainPositional = [...$mainPositional,...$this->mainTables]; // tables
        $associative['include-columns'] = implode(',',$this->searchMainColumns);
        $associative['regex'] = true;
      } else {
        WP_CLI::debug("This is a subdirectory based multisite.");
        /**
         * If this is a subdirectory type multisite, then we:
         *  - don't want to limit the tables
         *  - don't want this to be a regex search
         *  - do want to limit to columns, but ALL the columns
         */
        $mainPositional[] = $domainSearch;
        $mainPositional[] = $domainReplace;
        $associative['include-columns'] = implode(',',[...$this->searchOptionsColumns,...$this->searchMainColumns]);
      }


      /**
       * Technically we can have more than one primary and subsequent primary domains aren't necessarily the "parent" domain
       * (ie the domain listed in $table_prefix.'sites')
       * @todo we need to limit to JUST the domain in $table_prefix.'site';
       */
      if(isset($routeData['primary']) && $routeData['primary']) {
        $associative['network'] = true;
      }

      $searcherMainTables=new Search_Replace_Command();
      $searcherMainTables($mainPositional,$associative);

      WP_CLI::log("Network tables updated for %s.", $domainSearch);

      //WP_CLI::confirm("Update completed. Continue with the next one?");
    }

    $endTime = microtime(true);

    WP_CLI::log(sprintf("Total processing time was %ss", ($endTime - $startTime)));
    //@todo should we run a flush cache when we're done?

  }

  /**
   * For a given site, we'll need to update a collection of tables related to the site. Tables are named with the format
   * <prefix><blogid>_<table>
   * Given a prefix of `wp_`, a blog id of 2, and the table `options` the name is wp_2_options.
   * HOWEVER, if the blog id is 1, then the table name is `wp_options`
   * @param int $blogId
   * @return array
   */
  protected function processOptionsTables(int $blogId): array {
    return array_map(function ($table) use ($blogId){
      return $this->tblPrefix.((1 === $blogId) ? '' : $blogId . '_').$table;
    }, $this->optionsTables);
  }

  /**
   * Handles any flag parameters that might have been passed in with the command when called
   * @param array $assocFlags
   * @return void
   */
  protected function setFlags(array $assocFlags): void {
    if (isset($assocFlags['dry-run'])) {
      $this->associative['dry-run'] = Utils\get_flag_value($assocFlags,'dry-run', false);
      WP_CLI::debug(sprintf("dry-run has been set to %s", var_export($this->associative['dry-run'],true)));
    }

    if(isset($assocFlags['verbose'])) {
      $this->associative['verbose'] = Utils\get_flag_value($assocFlags,'verbose',false);
      WP_CLI::debug(sprintf("verbose has been set to %s", var_export($this->associative['verbose'],true)));
    }
  }

  /**
   * Retrieves the list of known sites from the database that need to be updated/processed
   * @return void
   * @uses get_sites()
   */
  protected function getSites(): void {
    $this->sites = array_map(static function ($site) {
      $site->url = sprintf('https://%s/',$site->domain);
      return (array) $site;
    },get_sites());
  }

  /**
   * Setter for the default/parent domain info
   *
   * @uses self::retrieveDefaultDomainInfo()
   * @throws ExitException
   */
  protected function setDefaultDomainInfo(): void {
    WP_CLI::debug('determining default domain info. Raw, then filtered.');
    WP_CLI::debug(var_export($this->rawRoutes,true));
    WP_CLI::debug(var_export($this->filteredRoutes,true));

    $this->defaultDomainInfo = self::retrieveDefaultDomainInfo($this->filteredRoutes);

    if(count($this->defaultDomainInfo) !== 1 ) {
      WP_CLI::warning(sprintf('Default domain info does not contain exactly one entry. It contains %d.', count($this->defaultDomainInfo)));
      WP_CLI::debug(var_export($this->filteredRoutes,true));
      WP_CLI::error("Exiting.");
    }
  }

  /**
   * Retrieves and saves the default replacement URL from the default domain information
   * @return void
   * @todo seems like some of these we could use a magic get and just return the correct data?
   */
  protected function setDefaultReplaceURL(): void {
    $this->defaultReplaceURLFull = array_key_first($this->defaultDomainInfo);
  }

  /**
   * Setter for default search url (ie the default/parent url we want to replace)
   * @return void
   * @todo seems like some of these we could use a magic get and just return the correct data?
   */
  protected function setDefaultSearchURL(): void {
    $this->defaultSearchURL = $this->defaultDomainInfo[$this->defaultReplaceURLFull]['production_url'];
  }

  /**
   * Setter for filtered routes
   * @return void
   */
  protected function setFilteredRoutes(): void {
    $this->filteredRoutes = self::getFilteredRoutes($this->rawRoutes,$this->appName);
  }

  /**
   * Reorders our route array so that subdomains (or sub-sub domains) of a domain are first. ie least specific to most
   * specific
   *
   * We need the default_domain to be processed LAST otherwise any domains that are sub(-sub)domains of it won't be
   * allowed to update their tables. This assumes that our default_domain is first in the list which it *should* be.
   * @todo do we need to search for default_domain, remove it from where it is, and then append it?
   * @return void
   */
  protected function orderFilteredRoutesByDomainLength(): void {
    uasort($this->filteredRoutes, static function ($a, $b) {
      $lena = substr_count($a['production_url'],'.');
      $lenb = substr_count($b['production_url'], '.' );
      if ($lena === $lenb) {
        return 0;
      }

      return ($lena < $lenb) ? 1 : -11;
    });
  }

  /**
   * Saves the raw, unfiltered route array
   * @param array $routes array of route information
   * @return void
   */
  protected function setRawRoutes(array $routes): void {
    $this->rawRoutes = $routes;
  }

  /**
   * Sets the value of the app's name
   * @param string $name App name as defined in the platform/upsun configuration file
   * @return void
   */
  protected function setAppName(string $name): void {
    WP_CLI::debug(sprintf("Setting app name as %s",$name));
    $this->appName = $name;
  }

  /**
   * Retrieve and store the table prefix as defined in wp-config
   * @return void
   */
  protected function getTablePrefix(): void {
    global $wpdb;
    $this->tblPrefix = $wpdb->base_prefix;
  }

  /**
   * Updates our array of tables to include the prefix as defined in wp-config
   * @return void
   */
  protected function updateTablesWithPrefix(): void {
    $this->mainTables = array_map(function ($table) {
      return $this->tblPrefix.$table;
    }, $this->mainTables);
  }

  /**
   * Returns the decoded routes data from the environment variable <pass-prefix>_ROUTES
   * @return string
   * @throws WP_CLI\ExitException
   */
  public static function getRouteFromEnvVar(): string {
    return \base64_decode(self::getEnvVar('ROUTES'));
  }

  /**
   * Retrieve an environment variable's value.
   * Note - assumes the variable starts with the value in self::envVarPrefix
   * @param string $varName
   * @return array|false|string
   * @throws WP_CLI\ExitException
   * @todo should/do we need to have it try the requested env var without the prefix?
   */
  public static function getEnvVar(string $varName) {
    $envVarToGet = self::$envVarPrefix.$varName;
    if(!\getenv($envVarToGet) || "" === \getenv($envVarToGet)) {
      WP_CLI::error(\sprintf("%s is not set or empty. Are you sure you're running on Platform.sh?", $envVarToGet));
    }
    return \getenv($envVarToGet);
  }

  /**
   * Determines which new URL is the "default" route
   *
   * In a new preview environment, a new ephermal domain is created and assigned to the environment. If additional domains
   * or subdomains have been added, then new URLs will be created for each of those as well. We need the one that
   * corresponds to main or default domain (the one typically marked as `default_domain` in the project settings).
   * However, the exact determination of which one isn't consistent without calling the API, so this is a best
   * guesstimate based on the information we have available.
   * @param array $routes list of filtered routes
   * @return array
   * @throws ExitException
   */
  public static function retrieveDefaultDomainInfo(array $routes): array {
    /**
     * we now have (filteredRoutes) a list of NEW domains that are connected to our application as keys, with an array
     * of values that include production_url which is our "from" url, as well as a primary attribute to indicate which
     * one(s) is(are) our default domain(s). Now we need the "primary" domain (aka default_domain). It *should* be the first item
     * in the array but should we rely on that assumption or should we array_filter so we know we're getting the correct
     * one?
     */
    $defaultDomainInfo = array_filter($routes, static function ($route) {
      return (isset($route['primary']) && $route['primary']);
    });

    if(0 === count($defaultDomainInfo)) {
      WP_CLI::debug("how did we hit zero default domains? passed in routes, then the return from the filter.");

      WP_CLI::debug(var_export($routes,true));
      WP_CLI::debug(var_export($defaultDomainInfo,true));

      WP_CLI::error("There were zero domains returned as the primary domain. I can't continue without a default domain. Exiting.");
    }

    /**
     * Normally there is only one, but not always. Grab the first one as it is *normally* what we would
     * consider the "parent" domain in a multisite.
     */
    if(1 !== count($defaultDomainInfo)) {
      WP_CLI::debug("More than one primary domain discovered. Using the first one from the collection.");
      $defaultDomainInfo = array_slice($defaultDomainInfo,0,1,true);
    }

    return $defaultDomainInfo;
  }

  /**
   * Parses the routes information and plucks those that are connected to an app container (ie ignores all the redirect
   * routes)
   * @param array $routes
   * @param string $appName
   * @return array
   */
  public static function getFilteredRoutes(array $routes, string $appName): array {
      return array_filter($routes, static function($route) use ($appName) {
        return (isset($route['upstream']) && $appName === $route['upstream']);
      });
  }

  /**
   * Parses the json routes data
   * @param string $routeInfo JSON string of route information
   * @return array
   * @throws WP_CLI\ExitException
   */
  public static function parseRouteJson(string $routeInfo): array {
    $routes = [];

    try {
      $routes = \json_decode($routeInfo, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
      WP_CLI::error(\sprintf('Unable to parse route information. Is it valid JSON? %s', $e->getMessage()));
    }

    return $routes;
  }
}
