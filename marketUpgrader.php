<?php
/*
 * Your installation or use of this SugarCRM file is subject to the applicable
 * terms available at
 * http://support.sugarcrm.com/Resources/Master_Subscription_Agreements/.
 * If you do not agree to all of the applicable terms or do not have the
 * authority to bind the entity as an authorized representative, then do not
 * install or use this SugarCRM file.
 *
 * Copyright (C) SugarCRM Inc. All rights reserved.
 */

/**
 * Exit script with error message
 * @param string $message
 * @return void
 */
function exitWithError($message): void
{
    global $logFileFP;
    if (!empty($logFileFP)) {
        fwrite($logFileFP, date('r') . ' - ' . $message . "\n");
        fclose($logFileFP);
    }
    die($message . PHP_EOL);
}

/**
 * Log message to the log file
 * @param string $message
 * @return void
 */
function logMsg(string $message): void
{
    global $logFileFP;
    if (empty($logFileFP)) {
        return;
    }
    fwrite($logFileFP, date('r') . ' - ' . $message . "\n");
}

/**
 * Check if the sugar instance is MTS
 * @return bool
 */
function isMTSInstance()
{
    return function_exists('shadow');
}

/**
 * Load user by name
 * @param string $name
 * @return SugarBean
 */
function loadUser(string $name): SugarBean
{
    global $db;
    $user = BeanFactory::newBean('Users');
    $params = [
        'user_name' => $name,
        'deleted' => 0,
        'status' => 'Active',
    ];

    $where = [];
    foreach ($params as $param => $value) {
        $where[] = sprintf('%s = %s', $param, $db->quoted($value));
    }

    $query = 'SELECT * FROM users WHERE ' . implode(' AND ', $where);
    $result = $db->query($query);
    if (($row = $db->fetchRow($result))) {
        $user->populateFromRow($row);
    }

    return $user;
}

/**
 * Retrieve upgrade history by id_name
 * @param string $idName
 * @return UpgradeHistory|null
 * @throws SugarQueryException
 */
function retrieveUpgradeHistoryByIdName(string $idName): ?UpgradeHistory
{
    $upgradeHistory = new UpgradeHistory();
    $query = new SugarQuery();
    $query->from($upgradeHistory);
    $query->where()->equals('id_name', $idName)
        ->queryAnd()->equals('status', 'installed')
        ->queryAnd()->equals('deleted', 0);
    $query->limit(1);
    $result = $upgradeHistory->fetchFromQuery($query);
    if (!empty($result)) {
        return array_shift($result);
    }
    return null;
}

/**
 * Save connector files to a temporary location
 * @param string $dir Backup dir
 * @return void
 */
function saveConnectorFiles(string $dir): void
{
    logMsg('Backup connector files');
    $connectorDir = 'custom/modules/Connectors/connectors';

    foreach (['sources', 'formatters'] as $source) {
        $sourceDir = "$connectorDir/$source";
        if (is_dir($sourceDir)) {
            logMsg('Backup connector dir: ' . $sourceDir);
            $destDir = "$dir/$source";
            if (mkdir_recursive($destDir, true)) {
                if (!copy_recursive($sourceDir, $destDir)) {
                    logMsg('Failed to backup connector dir: ' . $sourceDir);
                }
            } else {
                logMsg('Failed to create directory: ' . $destDir);
            }
        }
    }
}

/**
 * Restore connector files from a temporary location
 * @param string $dir Backup dir
 * @return void
 */
function restoreConnectorFiles($dir): void
{
    logMsg('Restore connector files');
    $connectorDir = 'custom/modules/Connectors/connectors';

    foreach (['sources', 'formatters'] as $source) {
        $backupDir = "$dir/$source";
        if (is_dir($backupDir)) {
            logMsg('Restore connector dir: ' . $backupDir);
            if (copy_recursive($backupDir, "$connectorDir/$source")) {
                logMsg('Delete backup directory: ' . $backupDir);
                rmdir_recursive($backupDir);
            } else {
                logMsg('Failed to restore from backup directory: ' . $backupDir);
            }
        }
        // remove market directory
        $marketDir = "$connectorDir/$source/ext/rest/salesfusion";
        if (is_dir($marketDir)) {
            logMsg('Delete Market connector dir: ' . $marketDir);
            rmdir_recursive($marketDir);
        }
    }

    // remove backup directory if empty
    if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
        logMsg('Delete backup directory: ' . $dir);
        rmdir_recursive($dir);
    }
}

/**
 * Save Market settings to the temporary file,
 * will be used in PostMarketMigrate script
 * @return void
 */
function saveMarketSettings(): void
{
    logMsg('Backup Market settings');
    $settingsFile = 'cache/upgrades/settings.bak';
    $source = SourceFactory::getSource('ext_rest_salesfusion');
    $settings = [
        'org_name' => $source->getProperty('organization_name'),
        'modules' => array_keys($source->getMapping()['beans']),
    ];
    $data = '<?php $settings = ' . var_export($settings, true) . ';';
    sugar_file_put_contents($settingsFile, $data);
}

/**
 * Restore Market settings from the temporary file
 * @return void
 */
function restoreMarketSettings(): void
{
    logMsg('Restore Market settings');
    $settingsFile = 'cache/upgrades/settings.bak';
    if (!file_exists($settingsFile)) {
        logMsg('Cache file for Market settings not found');
        return;
    }
    $settings = [];
    include $settingsFile;
    if ($settings) {
        $source = SourceFactory::getSource('ext_rest_salesfusion');
        $properties = $source->getProperties();
        $properties['organization_name'] = $settings['org_name'];
        $source->setProperties($properties);
        $source->saveConfig();
        $sourceName = 'ext_rest_salesfusion';
        $configFile = 'custom/modules/Connectors/metadata/display_config.php';
        $modules_sources = [];
        if (file_exists($configFile)) {
            require $configFile;
        }
        foreach ($modules_sources as $module => &$sources) {
            if (in_array($module, $settings['modules'])) {
                $sources[$sourceName] = $sourceName;
            } else {
                unset($sources[$sourceName]);
                if (empty($sources)) {
                    unset($modules_sources[$module]);
                }
            }
        }
        if (!write_array_to_file('modules_sources', $modules_sources, $configFile)) {
            logMsg('Cannot write $modules_sources to ' . $configFile);
        }
    } else {
        logMsg('No Market settings found');
    }
    @unlink($settingsFile);
}

/**
 * Upload package file
 * @param string $packageFile
 * @return array
 */
function uploadPackage($packageFile) {
    logMsg('Upload Market package');
    $tempFile = tempnam(sys_get_temp_dir(), 'API');
    if (!copy($packageFile, $tempFile)) {
        exitWithError('Failed to copy package file to temp file.');
    }
    // Mock a $_FILES array member, adding in _SUGAR_API_UPLOAD to allow file uploads
    $_FILES['upgrade_zip'] = [
        'name' => basename($packageFile),
        'type' => get_file_mime_type($packageFile),
        'tmp_name' => $tempFile,
        'error' => 0,
        'size' => filesize($tempFile),
        '_SUGAR_API_UPLOAD' => true,
    ];
    $packageApi = new PackageApiRest();
    return $packageApi->uploadPackage(new RestService(), []);
}

set_time_limit(0);

if (!($cmdOptions = getopt('z:l:s:u:t:'))) {
    $usageStr = <<<eoq2
    Usage:
        php {$argv[0]} -z marketZipFile -l logFile [-t pathToTemplate] -s pathToSugarInstance -u adminUser

    Example:
        php [path-to-script/]{$argv[0]} -z [path-to-market-package/]SugarMarket-MySQL-2.2.zip -l [path-to-log-file/]marketUpgrade.log -s path-to-sugar-instance/ -u admin
eoq2;
    exitWithError($usageStr);
}

$marketPackageFile = trim($cmdOptions['z'] ?? '');
$templatePath = trim($cmdOptions['t'] ?? '');
$sugarPath = trim($cmdOptions['s'] ?? '');
$sugarUser = trim($cmdOptions['u'] ?? '');
$logFile = trim($cmdOptions['l'] ?? '');

if (empty($marketPackageFile) ||
    empty($sugarPath) ||
    empty($sugarUser) ||
    empty($logFile) ||
    (isMTSInstance() && empty($templatePath))) {
    exitWithError('Missing required parameter(s).');
}

if (!is_file($marketPackageFile)) {
    exitWithError('Market package file ' . $marketPackageFile . ' does not exist.');
}

if (!is_dir($sugarPath)) {
    exitWithError('SugarCRM path ' . $sugarPath . ' does not exist.');
}

if (isMTSInstance() && !is_dir($templatePath)) {
    exitWithError('Template path ' . $templatePath . ' does not exist.');
}

$logFileFP = @fopen($logFile, 'a+');

if (empty($logFileFP)) {
    exitWithError("Unable to open log file: $logFile.");
}

if (!defined('sugarEntry')) {
    define('sugarEntry', true);
}

$marketPackageFile = realpath($marketPackageFile);
$sugarPath = realpath($sugarPath);

if (isMTSInstance()) {
    $templatePath = realpath($templatePath);
    chdir($templatePath);
    shadow($templatePath, $sugarPath, ['cache', 'upload', 'upgrades', 'config.php', 'custom', 'package_install.log', 'sugarcrm.log']);
} else {
    chdir($sugarPath);
}

require_once 'include/entryPoint.php';
require_once 'config.php';

$db = DBManagerFactory::getInstance();
$current_language = $sugar_config['default_language'];
if (empty($current_language)) {
    $current_language = 'en_us';
}
$current_user = loadUser($sugarUser);

if (empty($current_user->id) || !$current_user->is_admin) {
    exitWithError('Admin user ' . $sugarUser . ' not found.');
}

try {
    $upgradeHistory = retrieveUpgradeHistoryByIdName('ext_rest_salesfusion');

    if (!$upgradeHistory) {
        exitWithError('No installed Market package found.');
    }

    logMsg('Installed Market package found: ' . $upgradeHistory->version);
    $packageManager = new Sugarcrm\Sugarcrm\PackageManager\PackageManager();
    saveMarketSettings();
    $shouldBackup = version_compare($upgradeHistory->version, '2.1', '<');
    $backupDir = 'cache/upgrades/connector_backup';

    if ($shouldBackup) {
        saveConnectorFiles($backupDir);
    }

    logMsg('Uninstall Market package');
    $packageManager->uninstallPackage($upgradeHistory, false);

    if ($shouldBackup) {
        restoreConnectorFiles($backupDir);
    }

    $packageData = uploadPackage($marketPackageFile);

    if (is_array($packageData) && isset($packageData['status']) &&
        $packageData['status'] === 'staged' &&
        $packageData['file_install']) {

        $upgradeHistory = BeanFactory::retrieveBean('UpgradeHistory', $packageData['file_install']);

        if (is_null($upgradeHistory) || empty($upgradeHistory->id)) {
            exitWithError('Failed to retrieve upgrade history for package.');
        }

        logMsg('Install Market package');
        $upgradeHistory = $packageManager->installPackage($upgradeHistory);
        $packageData = $upgradeHistory->getData();

        if ($packageData['status'] === 'installed') {
            logMsg('Installed Market package: ' . print_r($packageData, true));
            restoreMarketSettings();
        } else {
            exitWithError('Failed to install package: ' . $packageData['status']);
        }
    } else {
        exitWithError('Failed to upload package.');
    }
} catch (Exception $e) {
    exitWithError('Failed to upgrade Market package: ' . $e->getMessage());
}
@fclose($logFileFP);
echo 'Success!' . PHP_EOL;
