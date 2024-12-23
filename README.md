upsun/wp-ms-dbu
====================
ms-dbu: **M**ulti**S**ite **D**ata**B**ase **U**pdater

Updates a multisite's (both subdirectory and sub/multi-domain) database when deployed in a new 
[preview environment](https://docs.platform.sh/glossary.html#preview-environment). 

Built for use on [Platform.sh](https://platform.sh/) and [Upsun.com](https://upsun.com/) but should be usable in other systems as well (see [Using](#using))


[![Build Status](https://travis-ci.org/platformsh/wp-ms-dba.svg?branch=master)](https://travis-ci.org/platformsh/wp-ms-dba)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing) | [Support](#support)

## Using
~~~
SYNOPSIS

  wp ms-dbu <command>

SUBCOMMANDS

  update       Updates WordPress multisites in non-production environments on Platform.sh.
  version      Displays current ms-dbu version

~~~

### Commands
#### update
~~~
ms-dbu update [--routes=<routes>] [--app-name=<app-name>]
~~~

~~~
    Updating the database...
    <snip>
    Success: Made 110 replacements.
    <snip>
    Individual site tables updated for <site>. Now updating network tables...
    <snip>
    Network tables updated for <site>.
    Total processing time was 0.315s
~~~

##### Options
~~~
[--routes=<routes>]
   JSON object that describes the routes for the environment. Only needed if PLATFORM_ROUTES is not set.

[--app-name=<app-name>]
   The app name as set in your app configuration. Only needed if PLATFORM_APPLICATION_NAME is not set

[--dry-run]
   Run the entire search/replace operation and show report, but don’t save changes to the database.

[--verbose]
   Prints rows to the console as they’re updated.
~~~

##### routes
If you are running this command in a Platform.sh/Upsun.com preview environments, you do not need to include the `--routes` 
parameter; `routes` will automatically be retrieved via the `PLATFORM_ROUTES` environment variable. If you are running 
the `update` command elsewhere, you will need to provide a JSON object representing your route information in the 
environment as the `--routes` parameter. See [routes-example.json](./routes-example.json) for example JSON structure 
for route information. The `upstream` property will need to include the name of the app as passed in via the `--app-name`
parameter (see [below](#app-name)).

Alternatively, you can base64 encode the JSON object and store it as an environment variable `PLATFORM_ROUTES` and the
command will automatically ingest the route information.

##### app-name
If you are running this command in a Platform.sh/Upsun.com preview environments, you do not need to include the 
`--app-name` parameter; `app-name` will automatically be retrieved via the `PLATFORM_APPLICATION_NAME` environment 
variable. If you are running the `update` command elsewhere, you will need to provide an app name that matches the 
`upstream` property in your routes json . See [routes](#routes).

Alternatively, you can store the app name as an environment variable `PLATFORM_APPLICATION_NAME` and the command will 
automatically retrieve the information.


#### version
~~~
web@app.0:~$ wp ms-dbu version
Version: 0.6.4
~~~

### Using on Platform.sh/Upsun.com
In your application config file (
[Platform.sh](https://docs.platform.sh/create-apps/app-reference/single-runtime-image.html) / 
[Upsun.com](https://docs.upsun.com/create-apps/app-reference/single-runtime-image.html) ) update your `build` and 
`deploy` hooks to include the following:

#### Build hook
```yaml
  build: |
    wp package install upsun/wp-ms-dbu
```

#### Deploy hook
```yaml
  deploy: |
    set -e
    wp cache flush
    PRODURL=$(echo $PLATFORM_ROUTES | base64 --decode | jq -r '[.[] | select(.primary == true)] | first | .production_url')
    if [ 'production' != "${PLATFORM_ENVIRONMENT_TYPE}" ] &&  wp site list --format=count --url="${PRODURL}" >/dev/null 2>&1; then
      echo "Updating the database...";
      wp ms-dbu update --url="${PRODURL}"
    else
      echo "Database appears to already be updated. Skipping.";
    fi
    
```
## Installing

Installing this package requires WP-CLI v2.5 or greater. Update to the latest stable release with `wp cli update`.

Once you've done so, you can install the latest stable version of this package with:

```bash
wp package install upsun/wp-ms-dba
```

To install the latest development version of this package, use the following command instead:

```bash
wp package install upsun/wp-ms-dba:dev-update
```

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by 
writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising 
this documentation.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should 
[search existing issues](https://github.com/upsun/wp-ms-dba/issues?q=label%3Abug%20) to see if there’s an existing 
resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please 
[create a new issue](https://github.com/platformsh/wp-ms-dba/issues/new). Include as much detail as you can, and clear 
steps to reproduce if possible. 

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/upsun/wp-ms-dba/issues/new) to 
discuss whether the feature is a good fit for the project.

## Support

GitHub issues aren't for general support questions, but there are other venues you can try: 
* [Community website](https://community.platform.sh/)
* [DevCenter](https://devcenter.upsun.com/)
* [Discord](https://discord.gg/platformsh)


