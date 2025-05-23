<?php
/**
 * Teampass - a collaborative passwords manager.
 * ---
 * This file is part of the TeamPass project.
 * 
 * TeamPass is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 * 
 * TeamPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * 
 * Certain components of this file may be under different licenses. For
 * details, see the `licenses` directory or individual file headers.
 * ---
 * @file      upgrade_run_3.0.0_fields.php
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2025 Teampass.net
 * @license   GPL-3.0
 * @see       https://www.teampass.net
 */

use EZimuel\PHPSecureSession;
use TeampassClasses\SuperGlobal\SuperGlobal;
use TeampassClasses\Language\Language;
use TeampassClasses\ConfigManager\ConfigManager;

// Load functions
require_once __DIR__.'/../sources/main.functions.php';

// init
loadClasses('DB');
$superGlobal = new SuperGlobal();
$lang = new Language();
error_reporting(E_ERROR | E_PARSE);
set_time_limit(600);
$_SESSION['CPM'] = 1;

// Load config
$configManager = new ConfigManager();
$SETTINGS = $configManager->getAllSettings();

require_once '../includes/language/english.php';
require_once '../includes/config/include.php';
require_once '../includes/config/settings.php';
require_once 'tp.functions.php';
require_once 'libs/aesctr.php';

// Prepare POST variables
$post_nb = filter_input(INPUT_POST, 'nb', FILTER_SANITIZE_NUMBER_INT);
$post_start = filter_input(INPUT_POST, 'start', FILTER_SANITIZE_NUMBER_INT);

// Load libraries
$superGlobal = new SuperGlobal();
$lang = new Language(); 

// Some init
$_SESSION['settings']['loaded'] = '';
$finish = false;
$next = ($post_nb + $post_start);

// Test DB connexion
$pass = defuse_return_decrypted(DB_PASSWD);
$server = (string) DB_HOST;
$pre = (string) DB_PREFIX;
$database = (string) DB_NAME;
$port = (int) DB_PORT;
$user = (string) DB_USER;

$db_link = mysqli_connect(
    $server,
    $user,
    $pass,
    $database,
    $port
);
if ($db_link) {
    $db_link->set_charset(DB_ENCODING);
} else {
    echo '[{"finish":"1", "msg":"", "error":"Impossible to get connected to server. Error is: ' . addslashes(mysqli_connect_error()) . '!"}]';
    exit();
}

// Get POST with user info
$post_user_info = json_decode(base64_decode(filter_input(INPUT_POST, 'info', FILTER_SANITIZE_FULL_SPECIAL_CHARS)));
$userLogin = $post_user_info[0];
$userPassword = Encryption\Crypt\aesctr::decrypt(base64_decode($post_user_info[1]), 'cpm', 128);
$userId = $post_user_info[2];
if (isset($userPassword) === false || empty($userPassword) === true
    || isset($userLogin) === false || empty($userLogin) === true
    || isset($userId) === false || empty($userId) === true
) {
    echo '[{"finish":"1", "msg":"", "error":"Error - The user is not identified! Please restart upgrade."}]';
    exit();
} else {
    // Get user's key
    // Get user info
    $userQuery = mysqli_fetch_array(
        mysqli_query(
            $db_link,
            'SELECT public_key, private_key
            FROM '.$pre.'users
            WHERE id = '.(int) $userId
        )
    );

    if (isset($userQuery['id']) === true && empty($userQuery['id']) === false
        && (is_null($userQuery['private_key']) === true || empty($userQuery['private_key']) === true || $userQuery['private_key'] === 'none')
    ) {
        echo '[{"finish":"1", "msg":"", "error":"Error - User has no private key! Please restart upgrade."}]';
        exit();
    } else {
        $userPrivateKey = decryptPrivateKey($userPassword, $userQuery['private_key']);
        $userPublicKey = $userQuery['public_key'];
    }
}

// Get total items
$rows = mysqli_query(
    $db_link,
    'SELECT id
    FROM '.$pre.'categories_items'
);
if (!$rows) {
    echo '[{"finish":"1" , "error":"'.mysqli_error($db_link).'"}]';
    exit();
}

$total = mysqli_num_rows($rows);

// Loop on Items
$rows = mysqli_query(
    $db_link,
    'SELECT id, data, encryption_type
    FROM '.$pre.'categories_items
    LIMIT '.$post_start.', '.$post_nb
);
if (!$rows) {
    echo '[{"finish":"1" , "error":"'.mysqli_error($db_link).'"}]';
    exit();
}

while ($data = mysqli_fetch_array($rows)) {
    if ($data['encryption_type'] !== 'teampass_aes') {
        // Decrypt with Defuse
        $passwd = defuseCryption(
            $data['data'],
            '',
            'decrypt'
        );

        // Encrypt with Object Key
        $cryptedStuff = doDataEncryption(html_entity_decode($passwd['string']));

        // Store new password in DB
        mysqli_query(
            $db_link,
            'UPDATE '.$pre."categories_items
            SET data = '".$cryptedStuff['encrypted']."', encryption_type = 'teampass_aes'
            WHERE id = ".$data['id']
        );

        // Insert in DB the new object key for this item by user
        mysqli_query(
            $db_link,
            'INSERT INTO `'.$pre."sharekeys_fields` (`increment_id`, `object_id`, `user_id`, `share_key`) 
            VALUES (NULL, '".$data['id']."', '".$userId."', '".encryptUserObjectKey($cryptedStuff['objectKey'], $userPublicKey)."');"
        );
    }
}

if ($next >= $total) {
    $finish = 1;
}

echo '[{"finish":"'.$finish.'" , "next":"'.$next.'", "error":""}]';
