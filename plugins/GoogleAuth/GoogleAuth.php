<?php

/**
 * GoogleAuth
 *
 * @author Kšištof Vinčo <ksistof.vinco@gmail.com>

 */
class GoogleAuth extends LMSPlugin
{
    const PLUGIN_NAME = 'GoogleAuth';
    const PLUGIN_DESCRIPTION = 'Enables google OAuth login to userpanel';
    const PLUGIN_AUTHOR = 'Kšištof Vinčo &lt;ksistof.vinco@gmail.com&gt;';
    const PLUGIN_DBVERSION = '2020012303';
    const plugin_directory_name = 'GoogleAuth';

    public function registerHandlers()
    {
        $this->handlers = array();
    }
}
