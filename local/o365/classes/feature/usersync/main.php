<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package local_o365
 * @author James McQuillan <james.mcquillan@remote-learner.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2014 onwards Microsoft Open Technologies, Inc. (http://msopentech.com/)
 */

namespace local_o365\feature\usersync;

class main {
    protected $clientdata = null;
    protected $httpclient = null;

    public function __construct(\local_o365\oauth2\clientdata $clientdata = null, \local_o365\httpclient $httpclient = null) {
        $this->clientdata = (!empty($clientdata)) ? $clientdata : \local_o365\oauth2\clientdata::instance_from_oidc();
        $this->httpclient = (!empty($httpclient)) ? $httpclient : new \local_o365\httpclient();
    }

    /**
     * Construct a user API client, accounting for unified api presence, and fall back to system api user if desired.
     *
     * @param int $muserid The userid to get the outlook token for. If you want to force a system API user client, use an empty
     *                     value here and set $systemfallback to true.
     * @return \local_o365\rest\o365api|bool A constructed user API client (unified or legacy), or false if error.
     */
    public function construct_user_api($muserid = null, $systemfallback = true) {
        $unifiedconfigured = \local_o365\rest\unified::is_configured();
        if ($unifiedconfigured === true) {
            $resource = \local_o365\rest\unified::get_resource();
        } else {
            $resource = \local_o365\rest\azuread::get_resource();
        }

        $token = null;
        if (!empty($muserid)) {
            $token = \local_o365\oauth2\token::instance($muserid, $resource, $this->clientdata, $this->httpclient);
        }
        if (empty($token) && $systemfallback === true) {
            $token = \local_o365\oauth2\systemtoken::instance(null, $resource, $this->clientdata, $this->httpclient);
        }
        if (empty($token)) {
            throw new \Exception('No token available for user #'.$muserid);
        }

        if ($unifiedconfigured === true) {
            $apiclient = new \local_o365\rest\unified($token, $this->httpclient);
        } else {
            $apiclient = new \local_o365\rest\azuread($token, $this->httpclient);
        }
        return $apiclient;
    }

    /**
     * Get all users in the configured directory.
     *
     * @param string|array $params Requested user parameters.
     * @param string $skiptoken A skiptoken param from a previous get_users query. For pagination.
     * @return array|null Array of user information, or null if failure.
     */
    public function get_users($params = 'default', $skiptoken = '') {
        $apiclient = $this->construct_user_api(0, true);
        return $apiclient->get_users($params, $skiptoken);
    }

    /**
     * Create a Moodle user from AzureAD user data.
     *
     * @param array $aaddata Array of AzureAD user data.
     * @return \stdClass An object representing the created Moodle user.
     */
    public function create_user_from_aaddata($aaddata) {
        global $CFG;

        require_once($CFG->dirroot.'/user/profile/lib.php');
        require_once($CFG->dirroot.'/user/lib.php');

        $newuser = (object)[
            'auth' => 'oidc',
            'username' => trim(\core_text::strtolower($aaddata['userPrincipalName'])),
            'email' => (isset($aaddata['mail'])) ? $aaddata['mail'] : '',
            'firstname' => (isset($aaddata['givenName'])) ? $aaddata['givenName'] : '',
            'lastname' => (isset($aaddata['surname'])) ? $aaddata['surname'] : '',
            'city' => (isset($aaddata['city'])) ? $aaddata['city'] : '',
            'country' => (isset($aaddata['country'])) ? $aaddata['country'] : '',
            'department' => (isset($aaddata['department'])) ? $aaddata['department'] : '',
            'lang' => (isset($aaddata['preferredLanguage'])) ? substr($aaddata['preferredLanguage'], 0, 2) : 'en',
            'confirmed' => 1,
            'timecreated' => time(),
            'mnethostid' => $CFG->mnet_localhost_id,
        ];
        $password = null;
        $newuser->idnumber = $newuser->username;

        if (!empty($newuser->email)) {
            if (email_is_not_allowed($newuser->email)) {
                unset($newuser->email);
            }
        }

        if (empty($newuser->lang) || !get_string_manager()->translation_exists($newuser->lang)) {
            $newuser->lang = $CFG->lang;
        }

        $newuser->timemodified = $newuser->timecreated;
        $newuser->id = user_create_user($newuser, false, false);

        // Save user profile data.
        profile_save_data($newuser);

        $user = get_complete_user_data('id', $newuser->id);
        if (!empty($CFG->{'auth_'.$newuser->auth.'_forcechangepassword'})) {
            set_user_preference('auth_forcepasswordchange', 1, $user);
        }
        // Set the password.
        update_internal_user_password($user, $password);

        // Trigger event.
        \core\event\user_created::create_from_userid($newuser->id)->trigger();

        return $user;
    }

    /**
     * Sync AzureAD Moodle users with the configured AzureAD directory.
     *
     * @param array $aadusers Array of AAD users from $this->get_users().
     * @return bool Success/Failure
     */
    public function sync_users(array $aadusers = array()) {
        global $DB, $CFG;
        $sql = 'SELECT user.username
                  FROM {user} user
                 WHERE user.auth = ? AND user.deleted = ? AND user.mnethostid = ?';
        $params = ['oidc', '0', $CFG->mnet_localhost_id];
        $existingusers = $DB->get_records_sql($sql, $params);
        foreach ($aadusers as $user) {
            $userupn = \core_text::strtolower($user['userPrincipalName']);
            if (!isset($existingusers[$userupn])) {
                try {
                    $this->create_user_from_aaddata($user);
                } catch (\Exception $e) {
                    if (!PHPUNIT_TEST) {
                        mtrace('Could not create user "'.$user['userPrincipalName'].'" Reason: '.$e->getMessage());
                    }
                }
            }
        }
        return true;
    }
}