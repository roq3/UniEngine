<?php

define('INSIDE', true);

$_EnginePath = './';

include($_EnginePath.'common.php');
include_once($_EnginePath . 'modules/session/_includes.php');
include_once($_EnginePath . 'modules/registration/_includes.php');

use UniEngine\Engine\Includes\Helpers\Users;
use UniEngine\Engine\Modules\Session;
use UniEngine\Engine\Modules\Registration;

includeLang('reg_ajax');
$Now = time();

header('access-control-allow-origin: *');

if(isset($_GET['register']))
{
    $JSONResponse = null;
    $JSONResponse['Errors'] = array();

    $normalizedInput = Registration\Input\normalizeUserInput($_GET);

    $Username = $normalizedInput['username'];
    $Password = $normalizedInput['password'];
    $CheckEmail = $normalizedInput['email']['original'];
    $Email = $normalizedInput['email']['escaped'];
    $Rules = $normalizedInput['hasAcceptedRules'];
    $GalaxyNo = $normalizedInput['galaxyNo'];
    $LangCode = $normalizedInput['langCode'];

    $userSessionIP = Users\Session\getCurrentIP();

    $validationResults = Registration\Validators\validateInputs($normalizedInput);

    foreach ($validationResults as $fieldName => $fieldValidationResult) {
        if ($fieldValidationResult['isSuccess']) {
            continue;
        }

        switch ($fieldValidationResult['error']['code']) {
            case 'USERNAME_TOO_SHORT':
                $JSONResponse['Errors'][] = 1;
                $JSONResponse['BadFields'][] = 'username';
                break;
            case 'USERNAME_TOO_LONG':
                $JSONResponse['Errors'][] = 2;
                $JSONResponse['BadFields'][] = 'username';
                break;
            case 'USERNAME_INVALID':
                $JSONResponse['Errors'][] = 3;
                $JSONResponse['BadFields'][] = 'username';
                break;
            case 'PASSWORD_TOO_SHORT':
                $JSONResponse['Errors'][] = 4;
                $JSONResponse['BadFields'][] = 'password';
                break;
        }
    }

    // Check if EMail is correct
    $EmailGood = false;
    $BannedDomains = str_replace('.', '\.', $_GameConfig['BannedMailDomains']);
    if(empty($Email))
    {
        // EMail is empty
        $JSONResponse['Errors'][] = 5;
        $JSONResponse['BadFields'][] = 'email';
    }
    else if($Email != $CheckEmail)
    {
        // EMail has illegal signs
        $JSONResponse['Errors'][] = 6;
        $JSONResponse['BadFields'][] = 'email';
    }
    else if(!is_email($Email))
    {
        // EMail is incorrect
        $JSONResponse['Errors'][] = 7;
        $JSONResponse['BadFields'][] = 'email';
    }
    else if(!empty($BannedDomains) && preg_match('#('.$BannedDomains.')+#si', $Email))
    {
        // EMail is on banned domains list
        $JSONResponse['Errors'][] = 8;
        $JSONResponse['BadFields'][] = 'email';
    }
    else
    {
        $EmailGood = true;
    }

    // PreCheck Galaxy
    if($GalaxyNo < 1)
    {
        // Galaxy not given
        $JSONResponse['Errors'][] = 13;
        $JSONResponse['BadFields'][] = 'galaxy';
    }
    else if($GalaxyNo > MAX_GALAXY_IN_WORLD)
    {
        // GalaxyNo is too high
        $JSONResponse['Errors'][] = 14;
        $JSONResponse['BadFields'][] = 'galaxy';
    }

    // Check if valid language has been selected
    if(empty($LangCode))
    {
        // Invalid language selected
        $JSONResponse['Errors'][] = 16;
    }

    // Check if Rules has been accepted
    if (!$Rules) {
        // Rules are not accepted
        $JSONResponse['Errors'][] = 9;
    }

    if (REGISTER_RECAPTCHA_ENABLE) {
        // TODO: Verify whether this needs sanitization
        $captchaUserValue = (
            isset($_GET['captcha_response']) ?
                $_GET['captcha_response'] :
                null
        );
        $reCaptchaValidationResult = Registration\Validators\validateReCaptcha([
            'responseValue' => $captchaUserValue,
            'currentSessionIp' => $userSessionIP
        ]);

        if (!($reCaptchaValidationResult['isValid'])) {
            // ReCaptcha validation failed
            $JSONResponse['Errors'][] = 10;
        }
    }

    if (
        $EmailGood === true &&
        $validationResult['username']['isSuccess'] === true
    ) {
        $takenParamsValidationResult = Registration\Validators\validateTakenParams([
            'username' => $Username,
            'email' => $Email,
        ]);

        if ($takenParamsValidationResult['isUsernameTaken']) {
            $JSONResponse['Errors'][] = 11;
            $JSONResponse['BadFields'][] = 'username';
        }
        if ($takenParamsValidationResult['isEmailTaken']) {
            $JSONResponse['Errors'][] = 12;
            $JSONResponse['BadFields'][] = 'email';
        }
    }

    if (empty($JSONResponse['Errors'])) {
        unset($JSONResponse['Errors']);

        $newPlanetCoordinates = Registration\Utils\Galaxy\findNewPlanetPosition([
            'preferredGalaxy' => $GalaxyNo
        ]);

        if ($newPlanetCoordinates !== null) {
            $Galaxy = $newPlanetCoordinates['galaxy'];
            $System = $newPlanetCoordinates['system'];
            $Planet = $newPlanetCoordinates['planet'];

            $passwordHash = Session\Utils\LocalIdentityV1\hashPassword([
                'password' => $Password,
            ]);

            $insertNewUserResult = Registration\Utils\Queries\insertNewUser([
                'username' => $Username,
                'passwordHash' => $passwordHash,
                'langCode' => $LangCode,
                'email' => $Email,
                'registrationIP' => $userSessionIP,
                'currentTimestamp' => $Now,
            ]);
            $UserID = $insertNewUserResult['userId'];

            // Update all MailChanges
            doquery("UPDATE {{table}} SET `ConfirmType` = 4 WHERE `NewMail` = '{$Email}' AND `ConfirmType` = 0;", 'mailchange');

            // Create a Planet for User
            include($_EnginePath.'includes/functions/CreateOnePlanetRecord.php');

            $PlanetID = CreateOnePlanetRecord($Galaxy, $System, $Planet, $UserID, $_Lang['MotherPlanet'], true);

            Registration\Utils\Queries\incrementUsersCounterInGameConfig();

            $referrerUserId = Registration\Utils\General\getRegistrationReferrerId();

            if ($referrerUserId !== null) {
                $registrationIPs = [
                    'r' => trim($userSessionIP),
                    'p' => trim(Users\Session\getCurrentOriginatingIP())
                ];

                if (empty($registrationIPs['p'])) {
                    unset($registrationIPs['p']);
                }

                $existingMatchingEnterLogIds = Registration\Utils\Queries\findEnterLogIPsWithMatchingIPValue([
                    'ips' => $registrationIPs,
                ]);

                Registration\Utils\Queries\insertReferralsTableEntry([
                    'referrerUserId' => $referrerUserId,
                    'referredUserId' => $UserID,
                    'timestamp' => $Now,
                    'registrationIPs' => $registrationIPs,
                    'existingMatchingEnterLogIds' => $existingMatchingEnterLogIds,
                ]);

                $Message = false;
                $Message['msg_id'] = '038';
                $Message['args'] = array('');
                $Message = json_encode($Message);

                SendSimpleMessage($referrerUserId, 0, $Now, 70, '007', '016', $Message);
            }

            $ActivationCode = md5(mt_rand(0, 99999999999));

            // Update User with new data
            Registration\Utils\Queries\updateUserFinalDetails([
                'userId' => $UserID,
                'motherPlanetId' => $PlanetID,
                'motherPlanetGalaxy' => $Galaxy,
                'motherPlanetSystem' => $System,
                'motherPlanetPlanetPos' => $Planet,
                'referrerId' => $referrerUserId,
                'activationCode' => (
                    REGISTER_REQUIRE_EMAILCONFIRM ?
                        $ActivationCode :
                        null
                )
            ]);

            // Send a invitation private msg
            $Message = false;
            $Message['msg_id'] = '022';
            $Message['args'] = array('');
            $Message = json_encode($Message);

            SendSimpleMessage($UserID, 0, $Now, 70, '004', '009', $Message);

            if (REGISTER_REQUIRE_EMAILCONFIRM) {
                include($_EnginePath.'includes/functions/SendMail.php');

                $mailContent = Registration\Components\RegistrationConfirmationMail\render([
                    'userId' => $UserID,
                    'login' => $Username,
                    'password' => $Password,
                    'gameName' => $_GameConfig['game_name'],
                    'universe' => $_Lang['RegMail_UniName'],
                    'activationCode' => $ActivationCode,
                ])['componentHTML'];

                $mailTitle = parsetemplate(
                    $_Lang['mail_title'],
                    [
                        'gameName' => $_GameConfig['game_name']
                    ]
                );

                SendMail($Email, $mailTitle, $mailContent);
            }

            if (isGameStartTimeReached($Now)) {
                $sessionTokenValue = Session\Utils\Cookie\packSessionCookie([
                    'userId' => $UserID,
                    'username' => $Username,
                    'obscuredPasswordHash' => Session\Utils\Cookie\createCookiePasswordHash([
                        'passwordHash' => $passwordHash,
                    ]),
                    'isRememberMeActive' => 0,
                ]);

                $JSONResponse['Code'] = 1;
                $JSONResponse['Cookie'][] = [
                    'Name' => getSessionCookieKey(),
                    'Value' => $sessionTokenValue
                ];
                $JSONResponse['Redirect'] = GAMEURL_UNISTRICT.'/overview.php';
            } else {
                $JSONResponse['Code'] = 2;
            }
        } else {
            $JSONResponse['Errors'][] = 15;
            $JSONResponse['BadFields'][] = 'email';
        }
    }
    die('regCallback('.json_encode($JSONResponse).');');
}
else
{
    header('Location: index.php');
    die('regCallback({});');
}

?>
