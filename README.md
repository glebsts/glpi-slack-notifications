# GSN GLPI plugin

Plugin for [GLPI](https://github.com/glpi-project/glpi) v9.x (tested
with [v9.5.5](https://github.com/glpi-project/glpi/releases/tag/9.5.5)) to send Slack notifications upon GLPI events (tickets and followup create/update).

## Version bump

1. CI builds [tiny image](https://hub.docker.com/_/scratch) with plugin and its dependencies, tagged from master branch
   as `glpi-slack-notifications:$TAG`
2. When you build your own docker image with GLPI, Dockerfile can pick `glpi-slack-notifications:$TAG` and embed it to
   GLPI image into plugin folder, so to update one need to bump version in Dockerfile, like this:
    1. ```COPY --from=<your-registry-path>/glpi-slack-notifications:$TAG ./glpi-gsn-plugin $APP_HOME/plugins/gsn```

## Dev

* PHP 7.3 is ok (default on debian10, but [Slack client plugin](https://github.com/jolicode/slack-php-api) latest
  version requires 7.4+)
* checkout glpi 9.5.5
* checkout repo content into `plugins/gsn`
* composer install in plugin dir
* ensure mysql is running and credentials are provided to glpi
* `sudo php -S localhost:80` in glpi dir

## Env setup

* `GSN_SLACK_BOT_TOKEN` env var should contain Slack bot token
* **NB!** GLPI users must have email configured same as Slack user email

## Install

0. Make sure env var is set (see above)
1. after GLPI start go to Setup -> Plugins, GSN -> turn on "Install" switch, turn on "Enable" switch
2. Setup -> Notifications - turn on "Enable followups via GSN", "Save"
3. Under "GSN followups configuration" you can test Slack message sending
