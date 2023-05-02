<?php

/**
 * Plugin install process
 *
 * @return boolean
 */
function plugin_gsn_install()
{
    global $PHPLOGGER;
    $PHPLOGGER->addRecord(Monolog\Logger::WARNING, "GSN->install");
    Config::setConfigurationValues('core', ['notifications_gsn' => 0]);
    return true;
}

/**
 * Plugin uninstall process
 *
 * @return boolean
 */
function plugin_gsn_uninstall()
{
    global $PHPLOGGER;
    $PHPLOGGER->addRecord(Monolog\Logger::WARNING, "GSN->uninstall");
    Config::deleteConfigurationValues('core', ['notifications_gsn']);
    return true;
}

/**
 * here and below GLPI hooks are declared
 */

function plugin_gsn_item_add(Ticket $ticket)
{
    return PluginGsnEvent::addTicket($ticket);
}

function plugin_gsn_item_update(Ticket $ticket)
{
    return PluginGsnEvent::updateTicket($ticket);
}

function plugin_gsn_followup_add(ITILFollowup $followup)
{
    return PluginGsnEvent::addFollowup($followup);
}

function plugin_gsn_followup_update(ITILFollowup $followup)
{
    return PluginGsnEvent::updateFollowup($followup);
}

function plugin_gsn_approval_add(TicketValidation $approval)
{
    return PluginGsnEvent::addApproval($approval);
}

function plugin_gsn_approval_update(TicketValidation $approval)
{
    return PluginGsnEvent::updateApproval($approval);
}
