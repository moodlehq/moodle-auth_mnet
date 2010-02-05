<?php

/**
 * @author Martin Dougiamas
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package moodle multiauth
 *
 * Authentication Plugin: Moodle Network Authentication
 *
 * Multiple host authentication support for Moodle Network.
 *
 * 2006-11-01  File created.
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.');    ///  It must be included from a Moodle page
}

require_once($CFG->libdir.'/authlib.php');

/**
 * Moodle Network authentication plugin.
 */
class auth_plugin_mnet extends auth_plugin_base {

    /**
     * Constructor.
     */
    function auth_plugin_mnet() {
        $this->authtype = 'mnet';
        $this->config = get_config('auth/mnet');
        $this->mnet = get_mnet_environment();
    }

    /**
     * This function is normally used to determine if the username and password
     * are correct for local logins. Always returns false, as local users do not
     * need to login over mnet xmlrpc.
     *
     * @param string $username The username
     * @param string $password The password
     * @return bool Authentication success or failure.
     */
    function user_login($username, $password) {
        return false; // print_error("mnetlocal");
    }

    /**
     * Return user data for the provided token, compare with user_agent string.
     *
     * @param  string $token    The unique ID provided by remotehost.
     * @param  string $UA       User Agent string.
     * @return array  $userdata Array of user info for remote host
     */
    function user_authorise($token, $useragent) {
        global $CFG, $SITE, $DB;
        $remoteclient = get_mnet_remote_client();
        require_once $CFG->dirroot . '/mnet/xmlrpc/serverlib.php';

        $mnet_session = $DB->get_record('mnet_session', array('token'=>$token, 'useragent'=>$useragent));
        if (empty($mnet_session)) {
            throw new mnet_server_exception(1, get_string('authfail_nosessionexists', 'mnet'));
        }

        // check session confirm timeout
        if ($mnet_session->confirm_timeout < time()) {
            throw new mnet_server_exception(2, get_string('authfail_sessiontimedout', 'mnet'));
        }

        // session okay, try getting the user
        if (!$user = $DB->get_record('user', array('id'=>$mnet_session->userid))) {
            throw new mnet_server_exception(3, get_string('authfail_usermismatch', 'mnet'));
        }

        $userdata = array();
        $userdata['username']                = $user->username;
        $userdata['email']                   = $user->email;
        $userdata['auth']                    = 'mnet';
        $userdata['confirmed']               = $user->confirmed;
        $userdata['deleted']                 = $user->deleted;
        $userdata['firstname']               = $user->firstname;
        $userdata['lastname']                = $user->lastname;
        $userdata['city']                    = $user->city;
        $userdata['country']                 = $user->country;
        $userdata['lang']                    = $user->lang;
        $userdata['timezone']                = $user->timezone;
        $userdata['description']             = $user->description;
        $userdata['mailformat']              = $user->mailformat;
        $userdata['maildigest']              = $user->maildigest;
        $userdata['maildisplay']             = $user->maildisplay;
        $userdata['htmleditor']              = $user->htmleditor;
        $userdata['wwwroot']                 = $this->mnet->wwwroot;
        $userdata['session.gc_maxlifetime']  = ini_get('session.gc_maxlifetime');
        $userdata['picture']                 = $user->picture;
        if (!empty($user->picture)) {
            $imagefile = make_user_directory($user->id, true) . "/f1.jpg";
            if (file_exists($imagefile)) {
                $userdata['imagehash'] = sha1(file_get_contents($imagefile));
            }
        }

        $userdata['myhosts'] = array();
        if($courses = get_my_courses($user->id, 'id', 'id, visible')) {
            $userdata['myhosts'][] = array('name'=> $SITE->shortname, 'url' => $CFG->wwwroot, 'count' => count($courses));
        }

        $sql = "
                SELECT
                    h.name as hostname,
                    h.wwwroot,
                    h.id as hostid,
                    count(c.id) as count
                FROM
                    {mnet_enrol_course} c,
                    {mnet_enrol_assignments} a,
                    {mnet_host} h
                WHERE
                    c.id      =  a.courseid   AND
                    c.hostid  =  h.id         AND
                    a.userid  = ? AND
                    c.hostid != ?
                GROUP BY
                    h.name,
                    h.id,
                    h.wwwroot";
        if ($courses = $DB->get_records_sql($sql, array($user->id, $remoteclient->id))) {
            foreach($courses as $course) {
                $userdata['myhosts'][] = array('name'=> $course->hostname, 'url' => $CFG->wwwroot.'/auth/mnet/jump.php?hostid='.$course->hostid, 'count' => $course->count);
            }
        }

        return $userdata;
    }

    /**
     * Generate a random string for use as an RPC session token.
     */
    function generate_token() {
        return sha1(str_shuffle('' . mt_rand() . time()));
    }

    /**
     * Starts an RPC jump session and returns the jump redirect URL.
     *
     * @param int $mnethostid id of the mnet host to jump to
     * @param string $wantsurl url to redirect to after the jump (usually on remote system)
     * @param boolean $wantsurlbackhere defaults to false, means that the remote system should bounce us back here
     *                                  rather than somewhere inside *its* wwwroot
     */
    function start_jump_session($mnethostid, $wantsurl, $wantsurlbackhere=false) {
        global $CFG, $USER, $DB;
        require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';

        // check remote login permissions
        if (! has_capability('moodle/site:mnetlogintoremote', get_context_instance(CONTEXT_SYSTEM))
                or is_mnet_remote_user($USER)
                or $USER->username == 'guest'
                or empty($USER->id)) {
            print_error('notpermittedtojump', 'mnet');
        }

        // check for SSO publish permission first
        if ($this->has_service($mnethostid, 'sso_sp') == false) {
            print_error('hostnotconfiguredforsso', 'mnet');
        }

        // set RPC timeout to 30 seconds if not configured
        // TODO: Is this needed/useful/problematic?
        if (empty($this->config->rpc_negotiation_timeout)) {
            set_config('rpc_negotiation_timeout', '30', 'auth/mnet');
        }

        // get the host info
        $mnet_peer = new mnet_peer();
        $mnet_peer->set_id($mnethostid);

        // set up the session
        $mnet_session = $DB->get_record('mnet_session',
                                   array('userid'=>$USER->id, 'mnethostid'=>$mnethostid,
                                   'useragent'=>sha1($_SERVER['HTTP_USER_AGENT'])));
        if ($mnet_session == false) {
            $mnet_session = new object();
            $mnet_session->mnethostid = $mnethostid;
            $mnet_session->userid = $USER->id;
            $mnet_session->username = $USER->username;
            $mnet_session->useragent = sha1($_SERVER['HTTP_USER_AGENT']);
            $mnet_session->token = $this->generate_token();
            $mnet_session->confirm_timeout = time() + $this->config->rpc_negotiation_timeout;
            $mnet_session->expires = time() + (integer)ini_get('session.gc_maxlifetime');
            $mnet_session->session_id = session_id();
            $mnet_session->id = $DB->insert_record('mnet_session', $mnet_session);
        } else {
            $mnet_session->useragent = sha1($_SERVER['HTTP_USER_AGENT']);
            $mnet_session->token = $this->generate_token();
            $mnet_session->confirm_timeout = time() + $this->config->rpc_negotiation_timeout;
            $mnet_session->expires = time() + (integer)ini_get('session.gc_maxlifetime');
            $mnet_session->session_id = session_id();
            $DB->update_record('mnet_session', $mnet_session);
        }

        // construct the redirection URL
        //$transport = mnet_get_protocol($mnet_peer->transport);
        $wantsurl = urlencode($wantsurl);
        $url = "{$mnet_peer->wwwroot}{$mnet_peer->application->sso_land_url}?token={$mnet_session->token}&idp={$this->mnet->wwwroot}&wantsurl={$wantsurl}";
        if ($wantsurlbackhere) {
            $url .= '&remoteurl=1';
        }

        return $url;
    }

    /**
     * This function confirms the remote (ID provider) host's mnet session
     * by communicating the token and UA over the XMLRPC transport layer, and
     * returns the local user record on success.
     *
     *   @param string    $token           The random session token.
     *   @param mnet_peer $remotepeer   The ID provider mnet_peer object.
     *   @return array The local user record.
     */
    function confirm_mnet_session($token, $remotepeer) {
        global $CFG, $DB;
        require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';

        // verify the remote host is configured locally before attempting RPC call
        if (! $remotehost = $DB->get_record('mnet_host', array('wwwroot' => $remotepeer->wwwroot, 'deleted' => 0))) {
            print_error('notpermittedtoland', 'mnet');
        }

        // set up the RPC request
        $mnetrequest = new mnet_xmlrpc_client();
        $mnetrequest->set_method('auth/mnet/auth.php/user_authorise');

        // set $token and $useragent parameters
        $mnetrequest->add_param($token);
        $mnetrequest->add_param(sha1($_SERVER['HTTP_USER_AGENT']));

        // Thunderbirds are go! Do RPC call and store response
        if ($mnetrequest->send($remotepeer) === true) {
            $remoteuser = (object) $mnetrequest->response;
        } else {
            foreach ($mnetrequest->error as $errormessage) {
                list($code, $message) = array_map('trim',explode(':', $errormessage, 2));
                if($code == 702) {
                    $site = get_site();
                    print_error('mnet_session_prohibited', 'mnet', $remotepeer->wwwroot, format_string($site->fullname));
                    exit;
                }
                $message .= "ERROR $code:<br/>$errormessage<br/>";
            }
            print_error("rpcerror", '', '', $message);
        }
        unset($mnetrequest);

        if (empty($remoteuser) or empty($remoteuser->username)) {
            print_error('unknownerror', 'mnet');
            exit;
        }

        if (user_not_fully_set_up($remoteuser)) {
            print_error('notenoughidpinfo', 'mnet');
            exit;
        }

        $firsttime = false;

        // get the local record for the remote user
        $localuser = $DB->get_record('user', array('username'=>$remoteuser->username, 'mnethostid'=>$remotehost->id));

        // add the remote user to the database if necessary, and if allowed
        // TODO: refactor into a separate function
        if (empty($localuser) || ! $localuser->id) {
            if (empty($this->config->auto_add_remote_users)) {
                print_error('nolocaluser', 'mnet');
            }
            $remoteuser->mnethostid = $remotehost->id;
            $remoteuser->firstaccess = time(); // First time user in this server, grab it here

            //TODO - username required to use PARAM_USERNAME before inserting into user table (MDL-16919)
            $remoteuser->id = $DB->insert_record('user', $remoteuser);
            $firsttime = true;
            $localuser = $remoteuser;
        }

        // check sso access control list for permission first
        if (!$this->can_login_remotely($localuser->username, $remotehost->id)) {
            print_error('sso_mnet_login_refused', 'mnet', '', array($localuser->username, $remotehost->name));
        }

        // update the local user record with remote user data
        foreach ((array) $remoteuser as $key => $val) {

            // TODO: fetch image if it has changed
            if ($key == 'imagehash') {
                $dirname = make_user_directory($localuser->id, true);
                $filename = "$dirname/f1.jpg";

                $localhash = '';
                if (file_exists($filename)) {
                    $localhash = sha1(file_get_contents($filename));
                } elseif (!file_exists($dirname)) {
                    mkdir($dirname);
                }

                if ($localhash != $val) {
                    // fetch image from remote host
                    $fetchrequest = new mnet_xmlrpc_client();
                    $fetchrequest->set_method('auth/mnet/auth.php/fetch_user_image');
                    $fetchrequest->add_param($localuser->username);
                    if ($fetchrequest->send($remotepeer) === true) {
                        if (strlen($fetchrequest->response['f1']) > 0) {
                            $imagecontents = base64_decode($fetchrequest->response['f1']);
                            file_put_contents($filename, $imagecontents);
                            $localuser->picture = 1;
                        }
                        if (strlen($fetchrequest->response['f2']) > 0) {
                            $imagecontents = base64_decode($fetchrequest->response['f2']);
                            file_put_contents($dirname.'/f2.jpg', $imagecontents);
                        }
                    }
                }
            }

            if($key == 'myhosts') {
                $localuser->mnet_foreign_host_array = array();
                foreach($val as $rhost) {
                    $name  = clean_param($rhost['name'], PARAM_ALPHANUM);
                    $url   = clean_param($rhost['url'], PARAM_URL);
                    $count = clean_param($rhost['count'], PARAM_INT);
                    $url_is_local = stristr($url , $CFG->wwwroot);
                    if (!empty($name) && !empty($count) && empty($url_is_local)) {
                        $localuser->mnet_foreign_host_array[] = array('name'  => $name,
                                                                      'url'   => $url,
                                                                      'count' => $count);
                    }
                }
            }

            $localuser->{$key} = $val;
        }

        $localuser->mnethostid = $remotepeer->id;
        if (empty($localuser->firstaccess)) { // Now firstaccess, grab it here
            $localuser->firstaccess = time();
        }

        $DB->update_record('user', $localuser);

        if (!$firsttime) {
            // repeat customer! let the IDP know about enrolments
            // we have for this user.
            // set up the RPC request
            $mnetrequest = new mnet_xmlrpc_client();
            $mnetrequest->set_method('auth/mnet/auth.php/update_enrolments');

            // pass username and an assoc array of "my courses"
            // with info so that the IDP can maintain mnet_enrol_assignments
            $mnetrequest->add_param($remoteuser->username);
            $fields = 'id, category, sortorder, fullname, shortname, idnumber, summary,
                       startdate, cost, currency, defaultrole, visible';
            $courses = get_my_courses($localuser->id, 'visible DESC,sortorder ASC', $fields);
            if (is_array($courses) && !empty($courses)) {
                // Second request to do the JOINs that we'd have done
                // inside get_my_courses() if we had been allowed
                $sql = "SELECT c.id,
                               cc.name AS cat_name, cc.description AS cat_description,
                               r.shortname as defaultrolename
                          FROM {course} c
                          JOIN {course_categories} cc ON c.category = cc.id
                          LEFT OUTER JOIN {role} r  ON c.defaultrole = r.id
                         WHERE c.id IN (" . join(',',array_keys($courses)) . ')';
                $extra = $DB->get_records_sql($sql);

                $keys = array_keys($courses);
                $defaultrolename = $DB->get_field('role', 'shortname', array('id'=>$CFG->defaultcourseroleid));
                foreach ($keys AS $id) {
                    if ($courses[$id]->visible == 0) {
                        unset($courses[$id]);
                        continue;
                    }
                    $courses[$id]->cat_id          = $courses[$id]->category;
                    $courses[$id]->defaultroleid   = $courses[$id]->defaultrole;
                    unset($courses[$id]->category);
                    unset($courses[$id]->defaultrole);
                    unset($courses[$id]->visible);

                    $courses[$id]->cat_name        = $extra[$id]->cat_name;
                    $courses[$id]->cat_description = $extra[$id]->cat_description;
                    if (!empty($extra[$id]->defaultrolename)) {
                        $courses[$id]->defaultrolename = $extra[$id]->defaultrolename;
                    } else {
                        $courses[$id]->defaultrolename = $defaultrolename;
                    }
                    // coerce to array
                    $courses[$id] = (array)$courses[$id];
                }
            } else {
                // if the array is empty, send it anyway
                // we may be clearing out stale entries
                $courses = array();
            }
            $mnetrequest->add_param($courses);

            // Call 0800-RPC Now! -- we don't care too much if it fails
            // as it's just informational.
            if ($mnetrequest->send($remotepeer) === false) {
                // error_log(print_r($mnetrequest->error,1));
            }
        }

        return $localuser;
    }


    /**
     * creates (or updates) the mnet session once
     * {@see confirm_mnet_session} and {@see complete_user_login} have both been called
     *
     * @param stdclass  $user the local user (must exist already
     * @param string    $token the jump/land token
     * @param mnet_peer $remotepeer the mnet_peer object of this users's idp
     */
    public function update_mnet_session($user, $token, $remotepeer) {
        global $DB;
        $session_gc_maxlifetime = 1440;
        if (isset($user->session_gc_maxlifetime)) {
            $session_gc_maxlifetime = $user->session_gc_maxlifetime;
        }
        if (!$mnet_session = $DB->get_record('mnet_session',
                                   array('userid'=>$user->id, 'mnethostid'=>$remotepeer->id,
                                   'useragent'=>sha1($_SERVER['HTTP_USER_AGENT'])))) {
            $mnet_session = new object();
            $mnet_session->mnethostid = $remotepeer->id;
            $mnet_session->userid = $user->id;
            $mnet_session->username = $user->username;
            $mnet_session->useragent = sha1($_SERVER['HTTP_USER_AGENT']);
            $mnet_session->token = $token; // Needed to support simultaneous sessions
                                           // and preserving DB rec uniqueness
            $mnet_session->confirm_timeout = time();
            $mnet_session->expires = time() + (integer)$session_gc_maxlifetime;
            $mnet_session->session_id = session_id();
            $mnet_session->id = $DB->insert_record('mnet_session', $mnet_session);
        } else {
            $mnet_session->expires = time() + (integer)$session_gc_maxlifetime;
            $DB->update_record('mnet_session', $mnet_session);
        }
    }



    /**
     * Invoke this function _on_ the IDP to update it with enrolment info local to
     * the SP right after calling user_authorise()
     *
     * Normally called by the SP after calling user_authorise()
     *
     * @param string $username The username
     * @param string $courses  Assoc array of courses following the structure of mnet_enrol_course
     * @return bool
     */
    function update_enrolments($username, $courses) {
        global $CFG, $DB;
        $remoteclient = get_mnet_remote_client();

        if (empty($username) || !is_array($courses)) {
            return false;
        }
        // make sure it is a user we have an in active session
        // with that host...
        if (!$userid = $DB->get_field('mnet_session', 'userid',
                            array('username'=>$username, 'mnethostid'=>$remoteclient->id))) {
            throw new mnet_server_exception(1, get_string('authfail_nosessionexists', 'mnet'));
        }

        if (empty($courses)) { // no courses? clear out quickly
            $DB->delete_records('mnet_enrol_assignments', array('hostid'=>$remoteclient->id, 'userid'=>$userid));
            return true;
        }

        // IMPORTANT: Ask for remoteid as the first element in the query, so
        // that the array that comes back is indexed on the same field as the
        // array that we have received from the remote client
        $sql = '
                SELECT
                    c.remoteid,
                    c.id,
                    c.cat_id,
                    c.cat_name,
                    c.cat_description,
                    c.sortorder,
                    c.fullname,
                    c.shortname,
                    c.idnumber,
                    c.summary,
                    c.startdate,
                    c.cost,
                    c.currency,
                    c.defaultroleid,
                    c.defaultrolename,
                    a.id as assignmentid
                FROM
                    {mnet_enrol_course} c
                LEFT JOIN {mnet_enrol_assignments} a
                ON
                   (a.courseid = c.id AND
                    a.hostid   = c.hostid AND
                    a.userid = ?)
                WHERE
                    c.hostid = ?';

        $currentcourses = $DB->get_records_sql($sql, array($userid, $remoteclient->id));

        $local_courseid_array = array();
        foreach($courses as $course) {

            $course['remoteid'] = $course['id'];
            $course['hostid']   =  (int)$remoteclient->id;
            $userisregd         = false;

            // First up - do we have a record for this course?
            if (!array_key_exists($course['remoteid'], $currentcourses)) {
                // No record - we must create it
                $course['id']  =  $DB->insert_record('mnet_enrol_course', (object)$course);
                $currentcourse = (object)$course;
            } else {
                // Pointer to current course:
                $currentcourse =& $currentcourses[$course['remoteid']];
                // We have a record - is it up-to-date?
                $course['id'] = $currentcourse->id;

                $saveflag = false;

                foreach($course as $key => $value) {
                    if ($currentcourse->$key != $value) {
                        $saveflag = true;
                        $currentcourse->$key = $value;
                    }
                }

                if ($saveflag) {
                    $DB->update_record('mnet_enrol_course', $currentcourse);
                }

                if (isset($currentcourse->assignmentid) && is_numeric($currentcourse->assignmentid)) {
                    $userisregd = true;
                }
            }

            // By this point, we should always have a $dataObj->id
            $local_courseid_array[] = $course['id'];

            // Do we have a record for this assignment?
            if ($userisregd) {
                // Yes - we know about this one already
                // We don't want to do updates because the new data is probably
                // 'less complete' than the data we have.
            } else {
                // No - create a record
                $assignObj = new stdClass();
                $assignObj->userid    = $userid;
                $assignObj->hostid    = (int)$remoteclient->id;
                $assignObj->courseid  = $course['id'];
                $assignObj->rolename  = $course['defaultrolename'];
                $assignObj->id = $DB->insert_record('mnet_enrol_assignments', $assignObj);
            }
        }

        // Clean up courses that the user is no longer enrolled in.
        $local_courseid_string = implode(', ', $local_courseid_array);
        $whereclause = " userid = ? AND hostid = ? AND courseid NOT IN ($local_courseid_string)";
        $DB->delete_records_select('mnet_enrol_assignments', $whereclause, array($userid, $remoteclient->id));
    }

    function prevent_local_passwords() {
        return true;
    }

    /**
     * Returns true if this authentication plugin is 'internal'.
     *
     * @return bool
     */
    function is_internal() {
        return false;
    }

    /**
     * Returns true if this authentication plugin can change the user's
     * password.
     *
     * @return bool
     */
    function can_change_password() {
        //TODO: it should be able to redirect, right?
        return false;
    }

    /**
     * Returns the URL for changing the user's pw, or false if the default can
     * be used.
     *
     * @return string
     */
    function change_password_url() {
        return '';
    }

    /**
     * Prints a form for configuring this authentication plugin.
     *
     * This function is called from admin/auth.php, and outputs a full page with
     * a form for configuring this plugin.
     *
     * @param object $config
     * @param object $err
     * @param array $user_fields
     */
    function config_form($config, $err, $user_fields) {
        global $CFG, $DB;

         $query = "
            SELECT
                h.id,
                h.name as hostname,
                h.wwwroot,
                h2idp.publish as idppublish,
                h2idp.subscribe as idpsubscribe,
                idp.name as idpname,
                h2sp.publish as sppublish,
                h2sp.subscribe as spsubscribe,
                sp.name as spname
            FROM
                {mnet_host} h
            LEFT JOIN
                {mnet_host2service} h2idp
            ON
               (h.id = h2idp.hostid AND
               (h2idp.publish = 1 OR
                h2idp.subscribe = 1))
            INNER JOIN
                {mnet_service} idp
            ON
               (h2idp.serviceid = idp.id AND
                idp.name = 'sso_idp')
            LEFT JOIN
                {mnet_host2service} h2sp
            ON
               (h.id = h2sp.hostid AND
               (h2sp.publish = 1 OR
                h2sp.subscribe = 1))
            INNER JOIN
                {mnet_service} sp
            ON
               (h2sp.serviceid = sp.id AND
                sp.name = 'sso_sp')
            WHERE
               ((h2idp.publish = 1 AND h2sp.subscribe = 1) OR
               (h2sp.publish = 1 AND h2idp.subscribe = 1)) AND
                h.id != ?
            ORDER BY
                h.name ASC";

        $id_providers       = array();
        $service_providers  = array();
        if ($resultset = $DB->get_records_sql($query, array($CFG->mnet_localhost_id))) {
            foreach($resultset as $hostservice) {
                if(!empty($hostservice->idppublish) && !empty($hostservice->spsubscribe)) {
                    $service_providers[]= array('id' => $hostservice->id, 'name' => $hostservice->hostname, 'wwwroot' => $hostservice->wwwroot);
                }
                if(!empty($hostservice->idpsubscribe) && !empty($hostservice->sppublish)) {
                    $id_providers[]= array('id' => $hostservice->id, 'name' => $hostservice->hostname, 'wwwroot' => $hostservice->wwwroot);
                }
            }
        }

        include "config.html";
    }

    /**
     * Processes and stores configuration data for this authentication plugin.
     */
    function process_config($config) {
        // set to defaults if undefined
        if (!isset ($config->rpc_negotiation_timeout)) {
            $config->rpc_negotiation_timeout = '30';
        }
        if (!isset ($config->auto_add_remote_users)) {
            $config->auto_add_remote_users = '0';
        }

        // save settings
        set_config('rpc_negotiation_timeout', $config->rpc_negotiation_timeout, 'auth/mnet');
        set_config('auto_add_remote_users',   $config->auto_add_remote_users,   'auth/mnet');

        return true;
    }

    /**
     * Poll the IdP server to let it know that a user it has authenticated is still
     * online
     *
     * @return  void
     */
    function keepalive_client() {
        global $CFG, $DB;
        $cutoff = time() - 300; // TODO - find out what the remote server's session
                                // cutoff is, and preempt that

        $sql = "
            select
                id,
                username,
                mnethostid
            from
                {user}
            where
                lastaccess > ? AND
                mnethostid != ?
            order by
                mnethostid";

        $immigrants = $DB->get_records_sql($sql, array($cutoff, $CFG->mnet_localhost_id));

        if ($immigrants == false) {
            return true;
        }

        $usersArray = array();
        foreach($immigrants as $immigrant) {
            $usersArray[$immigrant->mnethostid][] = $immigrant->username;
        }

        require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';
        foreach($usersArray as $mnethostid => $users) {
            $mnet_peer = new mnet_peer();
            $mnet_peer->set_id($mnethostid);

            $mnet_request = new mnet_xmlrpc_client();
            $mnet_request->set_method('auth/mnet/auth.php/keepalive_server');

            // set $token and $useragent parameters
            $mnet_request->add_param($users);

            if ($mnet_request->send($mnet_peer) === true) {
                if (!isset($mnet_request->response['code'])) {
                    debugging("Server side error has occured on host $mnethostid");
                    continue;
                } elseif ($mnet_request->response['code'] > 0) {
                    debugging($mnet_request->response['message']);
                }

                if (!isset($mnet_request->response['last log id'])) {
                    debugging("Server side error has occured on host $mnethostid\nNo log ID was received.");
                    continue;
                }
            } else {
                debugging("Server side error has occured on host $mnethostid: " .
                          join("\n", $mnet_request->error));
                break;
            }
            $mnethostlogssql = "
            SELECT
                mhostlogs.remoteid, mhostlogs.time, mhostlogs.userid, mhostlogs.ip,
                mhostlogs.course, mhostlogs.module, mhostlogs.cmid, mhostlogs.action,
                mhostlogs.url, mhostlogs.info, mhostlogs.username, c.fullname as coursename,
                c.modinfo
            FROM
                (
                    SELECT
                        l.id as remoteid, l.time, l.userid, l.ip, l.course, l.module, l.cmid,
                        l.action, l.url, l.info, u.username
                    FROM
                        {user} u
                        INNER JOIN {log} l on l.userid = u.id
                    WHERE
                        u.mnethostid = ?
                        AND l.id > ?
                    ORDER BY remoteid ASC
                    LIMIT 500
                ) mhostlogs
                INNER JOIN {course} c on c.id = mhostlogs.course
            ORDER by mhostlogs.remoteid ASC";

            $mnethostlogs = $DB->get_records_sql($mnethostlogssql, array($mnethostid, $mnet_request->response['last log id']));

            if ($mnethostlogs == false) {
                continue;
            }

            $processedlogs = array();

            foreach($mnethostlogs as $hostlog) {
                // Extract the name of the relevant module instance from the
                // course modinfo if possible.
                if (!empty($hostlog->modinfo) && !empty($hostlog->cmid)) {
                    $modinfo = unserialize($hostlog->modinfo);
                    unset($hostlog->modinfo);
                    $modulearray = array();
                    foreach($modinfo as $module) {
                        $modulearray[$module->cm] = urldecode($module->name);
                    }
                    $hostlog->resource_name = $modulearray[$hostlog->cmid];
                } else {
                    $hostlog->resource_name = '';
                }

                $processedlogs[] = array (
                                    'remoteid'      => $hostlog->remoteid,
                                    'time'          => $hostlog->time,
                                    'userid'        => $hostlog->userid,
                                    'ip'            => $hostlog->ip,
                                    'course'        => $hostlog->course,
                                    'coursename'    => $hostlog->coursename,
                                    'module'        => $hostlog->module,
                                    'cmid'          => $hostlog->cmid,
                                    'action'        => $hostlog->action,
                                    'url'           => $hostlog->url,
                                    'info'          => $hostlog->info,
                                    'resource_name' => $hostlog->resource_name,
                                    'username'      => $hostlog->username
                                 );
            }

            unset($hostlog);

            $mnet_request = new mnet_xmlrpc_client();
            $mnet_request->set_method('auth/mnet/auth.php/refresh_log');

            // set $token and $useragent parameters
            $mnet_request->add_param($processedlogs);

            if ($mnet_request->send($mnet_peer) === true) {
                if ($mnet_request->response['code'] > 0) {
                    debugging($mnet_request->response['message']);
                }
            } else {
                debugging("Server side error has occured on host $mnet_peer->ip: " .join("\n", $mnet_request->error));
            }
        }
    }

    /**
     * Receives an array of log entries from an SP and adds them to the mnet_log
     * table
     *
     * @param   array   $array      An array of usernames
     * @return  string              "All ok" or an error message
     */
    function refresh_log($array) {
        global $CFG, $DB;
        $remoteclient = get_mnet_remote_client();

        // We don't want to output anything to the client machine
        $start = ob_start();

        $returnString = '';
        $transaction = $DB->start_delegated_transaction();
        $useridarray = array();

        foreach($array as $logEntry) {
            $logEntryObj = (object)$logEntry;
            $logEntryObj->hostid = $remoteclient->id;

            if (isset($useridarray[$logEntryObj->username])) {
                $logEntryObj->userid = $useridarray[$logEntryObj->username];
            } else {
                $logEntryObj->userid = $DB->get_field('user', 'id', array('username'=>$logEntryObj->username, 'mnethostid'=>(int)$logEntryObj->hostid));
                if ($logEntryObj->userid == false) {
                    $logEntryObj->userid = 0;
                }
                $useridarray[$logEntryObj->username] = $logEntryObj->userid;
            }

            unset($logEntryObj->username);

            $logEntryObj = $this->trim_logline($logEntryObj);
            $insertok = $DB->insert_record('mnet_log', $logEntryObj, false);

            if ($insertok) {
                $remoteclient->last_log_id = $logEntryObj->remoteid;
            } else {
                $returnString .= 'Record with id '.$logEntryObj->remoteid." failed to insert.\n";
            }
        }
        $remoteclient->commit();
        $transaction->allow_commit();

        $end = ob_end_clean();

        if (empty($returnString)) return array('code' => 0, 'message' => 'All ok');
        return array('code' => 1, 'message' => $returnString);
    }

    /**
     * Receives an array of usernames from a remote machine and prods their
     * sessions to keep them alive
     *
     * @param   array   $array      An array of usernames
     * @return  string              "All ok" or an error message
     */
    function keepalive_server($array) {
        global $CFG, $DB;
        $remoteclient = get_mnet_remote_client();

        $CFG->usesid = true;

        // We don't want to output anything to the client machine
        $start = ob_start();

        // We'll get session records in batches of 30
        $superArray = array_chunk($array, 30);

        $returnString = '';

        foreach($superArray as $subArray) {
            $subArray = array_values($subArray);
            $instring = "('".implode("', '",$subArray)."')";
            $query = "select id, session_id, username from {mnet_session} where username in $instring";
            $results = $DB->get_records_sql($query);

            if ($results == false) {
                // We seem to have a username that breaks our query:
                // TODO: Handle this error appropriately
                $returnString .= "We failed to refresh the session for the following usernames: \n".implode("\n", $subArray)."\n\n";
            } else {
                foreach($results as $emigrant) {
                    session_touch($emigrant->session_id);
                }
            }
        }

        $end = ob_end_clean();

        if (empty($returnString)) return array('code' => 0, 'message' => 'All ok', 'last log id' => $remoteclient->last_log_id);
        return array('code' => 1, 'message' => $returnString, 'last log id' => $remoteclient->last_log_id);
    }

    /**
     * Cron function will be called automatically by cron.php every 5 minutes
     *
     * @return void
     */
    function cron() {
        global $DB;

        // run the keepalive client
        $this->keepalive_client();

        // admin/cron.php should have run srand for us
        $random100 = rand(0,100);
        if ($random100 < 10) {     // Approximately 10% of the time.
            // nuke olden sessions
            $longtime = time() - (1 * 3600 * 24);
            $DB->delete_records_select('mnet_session', "expires < ?", array($longtime));
        }
    }

    /**
     * Cleanup any remote mnet_sessions, kill the local mnet_session data
     *
     * This is called by require_logout in moodlelib
     *
     * @return   void
     */
    function prelogout_hook() {
        global $CFG, $USER;

        if (!is_enabled_auth('mnet')) {
            return;
        }

        // If the user is local to this Moodle:
        if ($USER->mnethostid == $this->mnet->id) {
            $this->kill_children($USER->username, sha1($_SERVER['HTTP_USER_AGENT']));

        // Else the user has hit 'logout' at a Service Provider Moodle:
        } else {
            $this->kill_parent($USER->username, sha1($_SERVER['HTTP_USER_AGENT']));

        }
    }

    /**
     * The SP uses this function to kill the session on the parent IdP
     *
     * @param   string  $username       Username for session to kill
     * @param   string  $useragent      SHA1 hash of user agent to look for
     * @return  string                  A plaintext report of what has happened
     */
    function kill_parent($username, $useragent) {
        global $CFG, $USER, $DB;

        require_once $CFG->dirroot.'/mnet/xmlrpc/client.php';
        $sql = "
            select
                *
            from
                {mnet_session} s
            where
                s.username   = ? AND
                s.useragent  = ? AND
                s.mnethostid = ?";

        $mnetsessions = $DB->get_records_sql($sql, array($username, $useragent, $USER->mnethostid));

        $ignore = $DB->delete_records('mnet_session',
                                 array('username'=>$username,
                                 'useragent'=>$useragent,
                                 'mnethostid'=>$USER->mnethostid));

        if (false != $mnetsessions) {
            $mnet_peer = new mnet_peer();
            $mnet_peer->set_id($USER->mnethostid);

            $mnet_request = new mnet_xmlrpc_client();
            $mnet_request->set_method('auth/mnet/auth.php/kill_children');

            // set $token and $useragent parameters
            $mnet_request->add_param($username);
            $mnet_request->add_param($useragent);
            if ($mnet_request->send($mnet_peer) === false) {
                debugging(join("\n", $mnet_request->error));
                return false;
            }
        }

        return true;
    }

    /**
     * The IdP uses this function to kill child sessions on other hosts
     *
     * @param   string  $username       Username for session to kill
     * @param   string  $useragent      SHA1 hash of user agent to look for
     * @return  string                  A plaintext report of what has happened
     */
    function kill_children($username, $useragent) {
        global $CFG, $USER, $DB;
        $remoteclient = get_mnet_remote_client();
        require_once $CFG->dirroot.'/mnet/xmlrpc/client.php';

        $userid = $DB->get_field('user', 'id', array('mnethostid'=>$CFG->mnet_localhost_id, 'username'=>$username));

        $returnstring = '';

        $mnetsessions = $DB->get_records('mnet_session', array('userid' => $userid, 'useragent' => $useragent));

        if (false == $mnetsessions) {
            $returnstring .= "Could find no remote sessions\n";
            $mnetsessions = array();
        }

        foreach($mnetsessions as $mnetsession) {
            // If this script is being executed by a remote peer, that means the user has clicked
            // logout on that peer, and the session on that peer can be deleted natively.
            // Skip over it.
            if (isset($remoteclient->id) && ($mnetsession->mnethostid == $remoteclient->id)) {
                continue;
            }
            $returnstring .=  "Deleting session\n";

            $mnet_peer = new mnet_peer();
            $mnet_peer->set_id($mnetsession->mnethostid);

            $mnet_request = new mnet_xmlrpc_client();
            $mnet_request->set_method('auth/mnet/auth.php/kill_child');

            // set $token and $useragent parameters
            $mnet_request->add_param($username);
            $mnet_request->add_param($useragent);
            if ($mnet_request->send($mnet_peer) === false) {
                debugging("Server side error has occured on host $mnetsession->mnethostid: " .
                          join("\n", $mnet_request->error));
            }
        }

        $ignore = $DB->delete_records('mnet_session',
                                 array('useragent'=>$useragent, 'userid'=>$userid));

        if (isset($remoteclient) && isset($remoteclient->id)) {
            session_kill_user($userid);
        }
        return $returnstring;
    }

    /**
     * When the IdP requests that child sessions are terminated,
     * this function will be called on each of the child hosts. The machine that
     * calls the function (over xmlrpc) provides us with the mnethostid we need.
     *
     * @param   string  $username       Username for session to kill
     * @param   string  $useragent      SHA1 hash of user agent to look for
     * @return  bool                    True on success
     */
    function kill_child($username, $useragent) {
        global $CFG, $DB;
        $remoteclient = get_mnet_remote_client();
        $session = $DB->get_record('mnet_session', array('username'=>$username, 'mnethostid'=>$remoteclient->id, 'useragent'=>$useragent));
        if (false != $session) {
            session_kill($session->session_id);
            return true;
        }
        return false;
    }

    /**
     * To delete a host, we must delete all current sessions that users from
     * that host are currently engaged in.
     *
     * @param   string  $sessionidarray   An array of session hashes
     * @return  bool                      True on success
     */
    function end_local_sessions(&$sessionArray) {
        global $CFG;
        if (is_array($sessionArray)) {
            while($session = array_pop($sessionArray)) {
                session_kill($session->session_id);
            }
            return true;
        }
        return false;
    }

    /**
     * Returns the user's image as a base64 encoded string.
     *
     * @param int $userid The id of the user
     * @return string     The encoded image
     */
    function fetch_user_image($username) {
        global $CFG, $DB;

        if ($user = $DB->get_record('user', array('username'=>$username, 'mnethostid'=>$CFG->mnet_localhost_id))) {
            $filename1 = make_user_directory($user->id, true) . "/f1.jpg";
            $filename2 = make_user_directory($user->id, true) . "/f2.jpg";
            $return = array();
            if (file_exists($filename1)) {
                $return['f1'] = base64_encode(file_get_contents($filename1));
            }
            if (file_exists($filename2)) {
                $return['f2'] = base64_encode(file_get_contents($filename2));
            }
            return $return;
        }
        return false;
    }

    /**
     * Returns the theme information and logo url as strings.
     *
     * @return string     The theme info
     */
    function fetch_theme_info() {
        global $CFG;

        $themename = "$CFG->theme";
        $logourl   = "$CFG->wwwroot/theme/$CFG->theme/images/logo.jpg";

        $return['themename'] = $themename;
        $return['logourl'] = $logourl;
        return $return;
    }

    /**
     * Determines if an MNET host is providing the nominated service.
     *
     * @param int    $mnethostid   The id of the remote host
     * @param string $servicename  The name of the service
     * @return bool                Whether the service is available on the remote host
     */
    function has_service($mnethostid, $servicename) {
        global $CFG, $DB;

        $sql = "
            SELECT
                svc.id as serviceid,
                svc.name,
                svc.description,
                svc.offer,
                svc.apiversion,
                h2s.id as h2s_id
            FROM
                {mnet_host} h,
                {mnet_service} svc,
                {mnet_host2service} h2s
            WHERE
                h.deleted = '0' AND
                h.id = h2s.hostid AND
                h2s.hostid = ? AND
                h2s.serviceid = svc.id AND
                svc.name = ? AND
                h2s.subscribe = '1'";

        return $DB->get_records_sql($sql, array($mnethostid, $servicename));
    }

    /**
     * Checks the MNET access control table to see if the username/mnethost
     * is permitted to login to this moodle.
     *
     * @param string $username   The username
     * @param int    $mnethostid The id of the remote mnethost
     * @return bool              Whether the user can login from the remote host
     */
    function can_login_remotely($username, $mnethostid) {
        global $DB;

        $accessctrl = 'allow';
        $aclrecord = $DB->get_record('mnet_sso_access_control', array('username'=>$username, 'mnet_host_id'=>$mnethostid));
        if (!empty($aclrecord)) {
            $accessctrl = $aclrecord->accessctrl;
        }
        return $accessctrl == 'allow';
    }

    function logoutpage_hook() {
        global $USER, $CFG, $redirect, $DB;

        if (!empty($USER->mnethostid) and $USER->mnethostid != $CFG->mnet_localhost_id) {
            $host = $DB->get_record('mnet_host', array('id'=>$USER->mnethostid));
            $redirect = $host->wwwroot.'/';
        }
    }

    /**
     * Trims a log line from mnet peer to limit each part to a length which can be stored in our DB
     *
     * @param object $logline The log information to be trimmed
     * @return object The passed logline object trimmed to not exceed storable limits
     */
    function trim_logline ($logline) {
        $limits = array('ip' => 15, 'coursename' => 40, 'module' => 20, 'action' => 40,
                        'url' => 255);
        foreach ($limits as $property => $limit) {
            if (isset($logline->$property)) {
                $logline->$property = substr($logline->$property, 0, $limit);
            }
        }

        return $logline;
    }


}
