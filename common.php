<?php

include('includes/phpBench.php'); $_BenchTool = new phpBench();
if(!empty($_BenchTool)){ $_BenchTool->simpleCountStart(false, 'telemetry__c'); }

session_start();

ini_set('default_charset', 'UTF-8');

$_GameConfig = [];
$_User = [];
$_Lang = [];
$_DBLink = '';
$ForceIPnUALog = false;
$Common_TimeNow = time();

include($_EnginePath . 'common.minimal.php');

if(!empty($_BenchTool)){ $_BenchTool->simpleCountStart(false, 'telemetry__c_maininc'); }

include($_EnginePath.'includes/constants.php');

if(defined('INSTALL_NOTDONE'))
{
    header('Location: ./install/');
    die();
}
include($_EnginePath.'includes/functions.php');
include($_EnginePath.'includes/unlocalised.php');
include($_EnginePath.'includes/ingamefunctions.php');
include($_EnginePath.'class/UniEngine_Cache.class.php');

$_MemCache = new UniEngine_Cache();

$_POST = SecureInput($_POST);
$_GET = SecureInput($_GET);

include($_EnginePath.'includes/vars.php');
include($_EnginePath.'includes/db.php');
include($_EnginePath.'includes/strings.php');

if(!empty($_BenchTool)){ $_BenchTool->simpleCountStop(); }

include($_EnginePath . 'includes/per_module/common/_includes.php');

// Load game configuration
$_GameConfig = loadGameConfig([
    'cache' => &$_MemCache
]);

if(!defined('UEC_INLOGIN'))
{
    $_User = CheckUserSession();
}

$_SkinPath = getSkinPath([
    'user' => &$_User,
    'enginePath' => $_EnginePath
]);

includeLang('tech');
includeLang('system');

if (isGameClosed($_GameConfig)) {
    $_DontShowMenus = true;

    if (
        isset($_CommonSettings['gamedisable_callback']) &&
        is_callable($_CommonSettings['gamedisable_callback'])
    ) {
        $_CommonSettings['gamedisable_callback']();
    }

    message(getGameCloseReason($_GameConfig), $_GameConfig['game_name']);
}

if(!isset($_SetAccessLogPath))
{
    $_SetAccessLogPath = '';
}
if(!isset($_SetAccessLogPreFilename))
{
    $_SetAccessLogPreFilename = '';
}
CreateAccessLog($_SetAccessLogPath, $_SetAccessLogPreFilename);

if (isIPBanned($_SERVER['REMOTE_ADDR'], $_GameConfig)) {
    message($_Lang['Game_blocked_for_this_IP'], $_GameConfig['game_name']);
}

if(isLogged())
{
    $isIPandUALogRefreshRequired = isIPandUALogRefreshRequired(
        $_User,
        [ 'timestamp' => $Common_TimeNow ]
    );

    if ($isIPandUALogRefreshRequired) {
        $ForceIPnUALog = true;
    }

    if(empty($_SESSION['IP_check']))
    {
        $_SESSION['IP_check'] = $_SERVER['REMOTE_ADDR'];
    }
    else
    {
        if($_SERVER['REMOTE_ADDR'] != $_SESSION['IP_check'])
        {
            if($_User['noipcheck'] != 1)
            {
                unset($_SESSION['IP_check']);
                header('Location: logout.php?badip=1');
                safeDie();
            }
            else
            {
                $_SESSION['IP_check'] = $_SERVER['REMOTE_ADDR'];
                $ForceIPnUALog = true;
            }
        }
    }

    if($ForceIPnUALog)
    {
        include("{$_EnginePath}includes/functions/IPandUA_Logger.php");
        IPandUA_Logger($_User);
    }

    if (!isGameStartTimeReached($Common_TimeNow)) {
        $serverStartMessage = sprintf(
            $_Lang['ServerStart_NotReached'],
            prettyDate('d m Y', SERVER_MAINOPEN_TSTAMP, 1),
            date('H:i:s', SERVER_MAINOPEN_TSTAMP)
        );

        message($serverStartMessage, $_Lang['Title_System']);
    }

    $isUserBlockedByActivationRequirement = isUserBlockedByActivationRequirement(
        $_User,
        [ 'timestamp' => $Common_TimeNow ]
    );

    if ($isUserBlockedByActivationRequirement) {
        $_DontShowMenus = true;
        message($_Lang['NonActiveBlock'], $_GameConfig['game_name']);
    }

    $userCookieBlockadeResult = handleUserBlockadeByCookie(
        $_User,
        [ 'timestamp' => $Common_TimeNow ]
    );

    if ($userCookieBlockadeResult) {
        $_DontShowMenus = true;
        message($_Lang['GameBlock_CookieStyle'], $_GameConfig['game_name']);
    }

    $userKickCheckResult = handleUserKick(
        $_User,
        [ 'timestamp' => $Common_TimeNow ]
    );

    if ($userKickCheckResult) {
        header('Location: logout.php?kicked=1');
        safeDie();
    }

    // --- Handle Tasks ---
    if(!isset($_UseMinimalCommon) || $_UseMinimalCommon !== true)
    {
        if(!isset($_DontShowMenus) || $_DontShowMenus !== true)
        {
            if(!empty($_User['tasks_done_parsed']['locked']))
            {
                $TaskBoxParseData = includeLang('tasks_infobox', true);
                $DoneTasks = 0;

                foreach($_User['tasks_done_parsed']['locked'] as $CatID => $CatTasks)
                {
                    if(strstr($CatID, 's'))
                    {
                        $CatID = str_replace('s', '', $CatID);
                        $ThisCatSkiped = true;
                    }
                    else
                    {
                        $ThisCatSkiped = false;
                    }
                    foreach($CatTasks as $TaskID)
                    {
                        unset($_User['tasks_done_parsed']['jobs'][$CatID][$TaskID]);
                        if($ThisCatSkiped === false OR ($ThisCatSkiped === true AND $_Vars_TasksData[$CatID]['skip']['tasksrew'] === true))
                        {
                            $TaskBoxLinks[$CatID] = 'cat='.$CatID.'&amp;showtask='.$TaskID;
                            foreach($_Vars_TasksData[$CatID]['tasks'][$TaskID]['reward'] as $RewardData)
                            {
                                Tasks_ParseRewards($RewardData, $_Vars_TasksDataUpdate);
                            }
                        }
                        $DoneTasks += 1;
                    }
                    if(Tasks_IsCatDone($CatID, $_User))
                    {
                        unset($_User['tasks_done_parsed']['jobs'][$CatID]);
                        if($ThisCatSkiped === false OR ($ThisCatSkiped === true AND $_Vars_TasksData[$CatID]['skip']['catrew'] === true))
                        {
                            $TaskBoxLinks[$CatID] = 'mode=log&amp;cat='.$CatID;
                            foreach($_Vars_TasksData[$CatID]['reward'] as $RewardData)
                            {
                                Tasks_ParseRewards($RewardData, $_Vars_TasksDataUpdate);
                            }
                        }
                    }
                    else
                    {
                        if(empty($_User['tasks_done_parsed']['jobs']))
                        {
                            unset($_User['tasks_done_parsed']['jobs']);
                        }
                    }
                }

                if(empty($_User['tasks_done_parsed']['jobs']))
                {
                    unset($_User['tasks_done_parsed']['jobs']);
                }

                if(!empty($TaskBoxLinks))
                {
                    if($DoneTasks > 1)
                    {
                        $TaskBoxParseData['Task'] = $TaskBoxParseData['MoreTasks'];
                    }
                    else
                    {
                        $TaskBoxParseData['Task'] = $TaskBoxParseData['OneTask'];
                    }
                    foreach($TaskBoxLinks as $CatID => $LinkData)
                    {
                        $TaskBoxParseData['CatLinks'][] = sprintf($TaskBoxParseData['CatLink'], $LinkData, $TaskBoxParseData['Names'][$CatID]);
                    }
                    $TaskBoxParseData['CatLinks'] = implode(', ', $TaskBoxParseData['CatLinks']);
                    GlobalTemplate_AppendToTaskBox(parsetemplate(gettemplate('tasks_infobox'), $TaskBoxParseData));
                }

                unset($_User['tasks_done_parsed']['locked']);
                $_User['tasks_done'] = json_encode($_User['tasks_done_parsed']);

                GlobalTemplate_AppendToTaskBox(parsetemplate(gettemplate('tasks_infobox'), $TaskBoxParseData));

                // Apply updates on the DB and global vars
                $taskUpdatesApplicationResult = applyTaskUpdates(
                    $_Vars_TasksDataUpdate,
                    [
                        'unixTimestamp' => $Common_TimeNow,
                        'user' => $_User
                    ]
                );

                foreach ($taskUpdatesApplicationResult['devlogEntries'] as $entry) {
                    $UserDev_Log[] = $entry;
                }
                foreach ($taskUpdatesApplicationResult['userUpdatedEntries'] as $entry) {
                    $_User[$entry['key']] += $entry['value'];
                }
            }
        }
    }
    // --- Handling Tasks ends here ---

    if(!isset($_AllowInVacationMode) || $_AllowInVacationMode != true)
    {
        // If this place do not allow User to be in VacationMode, show him a message if it's necessary
        if(isOnVacation())
        {
            $MinimalVacationTime = ($_User['pro_time'] > $_User['vacation_starttime'] ? MINURLOP_PRO : MINURLOP_FREE) + $_User['vacation_starttime'];
            $VacationMessage = sprintf($_Lang['VacationTill'], date('d.m.Y H:i:s', $MinimalVacationTime));
            if($MinimalVacationTime <= $Common_TimeNow)
            {
                $VacationMessage .= $_Lang['VacationSetOff'];
            }
            message($VacationMessage, $_Lang['Vacation']);
        }
    }

    if(!isset($_UseMinimalCommon) || $_UseMinimalCommon !== true)
    {
        try {
            // Change Planet (if user wants to do this)
            $planetChangeID = getPlanetChangeRequestedID($_GET);

            if ($planetChangeID) {
                SetSelectedPlanet($_User, $planetChangeID);
            }

            $_Planet = fetchCurrentPlanetData($_User);
            $_GalaxyRow = fetchGalaxyData($_Planet);

            if (
                !isset($_BlockFleetHandler) ||
                $_BlockFleetHandler !== true
            ) {
                $FleetHandlerReturn = FlyingFleetHandler($_Planet);
                if (
                    isset($FleetHandlerReturn['ThisMoonDestroyed']) &&
                    $FleetHandlerReturn['ThisMoonDestroyed']
                ) {
                    // Redirect User to Planet (from Destroyed Moon)
                    $motherPlanetID = $_User['id_planet'];

                    SetSelectedPlanet($_User, $motherPlanetID);

                    $_Planet = fetchCurrentPlanetData($_User);

                    if ($_GalaxyRow['id_planet'] != $_Planet['id']) {
                        $_GalaxyRow = fetchGalaxyData($_Planet);
                    }
                }
            }
        } catch (UniEnginePlanetDataFetchException $error) {
            message($_Lang['FatalError_PlanetRowEmpty'], 'FatalError');

            die();
        }

        if (!isset($_DontForceRulesAcceptance) || $_DontForceRulesAcceptance !== true) {
            if (isRulesAcceptanceRequired($_User, $_GameConfig)) {
                if (
                    isset($_DontShowRulesBox) &&
                    $_DontShowRulesBox === true
                ) {
                    message($_Lang['RulesAcceptBox_CantUseFunction'], $_Lang['SystemInfo']);

                    die();
                }

                if (
                    !defined("IN_RULES") ||
                    IN_RULES !== true
                ) {
                    header('Location: rules.php');
                    safeDie();
                }

                // FIXME: do not determine it here, move to "rules.php"
                $_ForceRulesAcceptBox = true;
            }
        }

        if (
            (
                !isset($_DontCheckPolls) ||
                $_DontCheckPolls !== true
            ) &&
            $_User['isAI'] != 1 &&
            $_User['register_time'] < ($Common_TimeNow - TIME_DAY)
        ) {
            $pollsCount = fetchObligatoryPollsCount($_User['id']);

            if ($pollsCount > 0) {
                message(sprintf($_Lang['YouHaveToVoteInSurveys'], $pollsCount), $_Lang['SystemInfo'], 'polls.php', 10);
            }
        }
    }
}
else
{
    $_DontShowMenus = true;
}

if(!empty($_BenchTool)){ $_BenchTool->simpleCountStop(); }

?>
