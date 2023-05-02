<?php

include('gsn.php');
require_once('vendor/autoload.php');

define('PLUGIN_GSN_VERSION', '0.0.6');

// Minimal GLPI version, inclusive
define("PLUGIN_GSN_MIN_GLPI_VERSION", "9.5.5");
// Maximum GLPI version, exclusive
define("PLUGIN_GSN_MAX_GLPI_VERSION", "10.0.0");

/**
 * Init hooks of the plugin.
 * REQUIRED
 *
 * @return void
 */
function plugin_init_gsn()
{
    global $PHPLOGGER, $PLUGIN_HOOKS;
    $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "GSN->init");
    $PLUGIN_HOOKS['csrf_compliant']['gsn'] = true;

    if (Plugin::isPluginActive('gsn')) {
        $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "GSN: is active, registering mode");

        Notification_NotificationTemplate::registerMode(
            Gsn::MODE_GSN, //mode itself
            __('GLPI Slack Integration', 'plugin_gsn'),                //label
            'gsn'                                   //plugin name
        );
        $PHPLOGGER->addRecord(Monolog\Logger::DEBUG, "GSN: is active, registering classes");
        Plugin::registerClass('PluginGsnNotificationEventGsn');
        Plugin::registerClass(JoliCode\Slack\ClientFactory::class);

        // add hook for tickets, followups and approvals creation
        $PLUGIN_HOOKS['item_add']['gsn'] = [
            Ticket::class => 'plugin_gsn_item_add',
            ITILFollowup::class => 'plugin_gsn_followup_add',
            TicketValidation::class => 'plugin_gsn_approval_add',
        ];

        // add hook for tickets, followups and approvals update
        $PLUGIN_HOOKS['item_update']['gsn'] = [
            Ticket::class => 'plugin_gsn_item_update',
            ITILFollowup::class => 'plugin_gsn_followup_update',
            TicketValidation::class => 'plugin_gsn_approval_update',
        ];
    }
}

/**
 * Get the name and the version of the plugin
 * REQUIRED
 *
 * @return array
 */
function plugin_version_gsn()
{
    return [
        'name' => 'gsn',
        'version' => PLUGIN_GSN_VERSION,
        'author' => '<a href="https://github.com/glebsts">Gleb Štšenov\'</a>',
        'license' => 'ISC',
        'homepage' => 'https://github.com/glebsts',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GSN_MIN_GLPI_VERSION,
                'max' => PLUGIN_GSN_MAX_GLPI_VERSION,
            ]
        ],
    ];
}


/**
 * Check pre-requisites before install
 * OPTIONAL, but recommended
 *
 * @return boolean
 */
function plugin_gsn_check_prerequisites()
{
    return true;
}

/**
 * Check configuration process
 *
 * @param boolean $verbose Whether to display message on failure. Defaults to false
 *
 * @return boolean
 */
function plugin_gsn_check_config($verbose = false)
{
    global $PHPLOGGER;

    if (getenv('GSN_SLACK_BOT_TOKEN')) { // Your configuration check
        return true;
    } else {
        if ($verbose) {
            $PHPLOGGER->addRecord(Monolog\Logger::ERROR, "GSN->check_config: slack token env var `GSN_SLACK_BOT_TOKEN` not found");
        }
    }

    $PHPLOGGER->addRecord(Monolog\Logger::WARNING, "GSN->check_config: " . 'Installed / not configured');
    echo __('Installed / not configured', 'gsn');

    return false;
}
