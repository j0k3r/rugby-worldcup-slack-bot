<?php

/**
 * Rugby World Cup Bot for Slack.
 *
 * It uses the unofficial World Rugby json API (the one used for their mobile app iOS/Android).
 * It will post a message :
 *   - when a matche starts
 *   - and of course, for every point
 *
 * You will need a token from Slack.
 * Jump at https://api.slack.com/ under the "Authentication" part and you will find your token.
 *
 * @author j0k <jeremy.benoist@gmail.com>
 * @license MIT
 */

/**
 * All the configuration are just below
 */

// Slack stuff
const SLACK_TOKEN      = 'XXXXXXXXXXXXXXXXXXXXXXXXXX';
const SLACK_CHANNEL    = 'rugby-world-cup';
const SLACK_BOT_NAME   = 'Rugby WorldCup Bot';
const SLACK_BOT_AVATAR = 'http://i.imgur.com/qOQuXfl.jpg';

const USE_PROXY     = false;
const PROXY         = 'http://myproxy:3128';
// If a proxy authentification is needed, set PROXY_USERPWD to "user:password"
const PROXY_USERPWD = false;

// Set to the language for updates
const LANG = 'en';

$language = array(
  'fr' => array(
    'Le match',
    'commence',
    'Score',
    'par',
    'Temps',
    'Mi-temps',
    'Reprise',
    'Fin du match',
  ),
  'en' => array(
    'The match between',
    'has started',
    'Score',
    'by',
    'Time',
    'Half time',
    'Kick-off',
    'Full time',
  )
);

/**
 * Below this line, you should modify at your own risk
 */

function getUrl($url)
{
  if (!USE_PROXY)
  {
    return file_get_contents($url);
  }

  $ch = curl_init($url);
  $options = array(
    CURLOPT_HEADER => 0,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_FOLLOWLOCATION => 1,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_PROXY => PROXY,
  );

  if (PROXY_USERPWD)
  {
    $options[CURLOPT_PROXYUSERPWD] = PROXY_USERPWD;
  }

  curl_setopt_array($ch, $options);

  $response = curl_exec($ch);
  if ($response !== false)
  {
    curl_close($ch);
    return $response;
  }

  var_dump(curl_error($ch));
  curl_close($ch);
  die();
}

function postToSlack($text, $attachments_fields = array(), $last_message = '')
{
  if ($text == $last_message)
  {
    var_dump('duplicate message: '.$text);
    return;
  }

  $slackUrl = 'https://slack.com/api/chat.postMessage?token='.SLACK_TOKEN.
    '&channel='.urlencode(SLACK_CHANNEL).
    '&username='.urlencode(SLACK_BOT_NAME).
    '&icon_url='.SLACK_BOT_AVATAR.
    '&unfurl_links=1&parse=full&pretty=1'.
    '&text='.urlencode($text);

  if (!empty($attachments_fields))
  {
    $fields = array();
    foreach ($attachments_fields as $key => $value)
    {
      $fields[] = array(
        'title' => $key,
        'value' => $value,
        'short' => true,
      );
    }

    $slackUrl .= '&attachments='.urlencode('[{"fields":'.json_encode($fields).'}]');
  }

  var_dump(getUrl($slackUrl));
}

$dbFile = './rugbyWorldCupDB.json';

$db = json_decode(file_get_contents($dbFile), true);
$response = json_decode(getUrl('http://cmsapi.pulselive.com/rugby/event/1238/schedule?language='.LANG), true);

if (!isset($response['matches']))
{
  var_dump('matches not here');
  die();
}

// find live matches
foreach ($response['matches'] as $match)
{
  // status: U, LT1, L1, LHT, L2, C
  if ('U' !== $match['status'] && 'C' !== $match['status'] && $match['matchId'] !== $db['live_match'])
  {
    $db = array(
      'live_match' => $match['matchId'],
      'last_second' => 0,
      'last_message' => '',
    );

    $summary = json_decode(getUrl('http://cmsapi.pulselive.com/rugby/match/'.$match['matchId'].'/summary?language='.LANG), true);

    // build player cache
    $players = array();
    foreach ($summary['teams'] as $key => $team)
    {
      foreach ($team['teamList']['list'] as $player)
      {
        $players[$key][$player['player']['id']] = array(
          'name' => $player['player']['name']['display'],
          'position' => $player['positionLabel'],
        );
      }
    }

    $db['players'] = $players;

    // notify slack & save data
    postToSlack(':zap: '.$language[LANG][0].' '.$match['teams'][0]['name'].' / '.$match['teams'][1]['name'].' '.$language[LANG][1].'! http://www.rugbyworldcup.com/match/'.$match['matchId'].'#blog');
    file_put_contents($dbFile, json_encode($db));
    return;
  }
}

if (0 == $db['live_match'])
{
  var_dump('no live match');
  return;
}

$response = json_decode(getUrl('http://cmsapi.pulselive.com/rugby/match/'.$db['live_match'].'/timeline?language='.LANG.'&client=slack-bot'), true);

if (!isset($response['timeline']))
{
  var_dump('timeline not here');
  return;
}

$posts = $response['timeline'];

foreach ($posts as $post)
{
  if ($post['time']['secs'] < $db['last_second'])
  {
    continue;
  }

  // only notify this kind of event
  if (!in_array($post['group'], array('Pen', 'M Pen', 'Try', 'YC', 'Con', 'MS', 'Sub On', 'Sub Off')))
  {
    continue;
  }

  $defaultMessage = $post['typeLabel'].' ('.$response['match']['teams'][$post['teamIndex']]['name'].')';

  $player = '';
  if (isset($post['playerId']))
  {
    $player = $db['players'][$post['teamIndex']][$post['playerId']]['name'].' ('.$db['players'][$post['teamIndex']][$post['playerId']]['position'].')';
  }

  switch ($post['group'])
  {
    // penality
    case 'Pen':
      $message = ':thumbsup: '.$defaultMessage.' +'.$post['points'].', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    // missed penality
    case 'M Pen':
      $message = ':thumbsdown: '.$defaultMessage.', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    case 'Try':
      $message = ':rugby_football: '.$defaultMessage.' +'.$post['points'].', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    // yellow card
    case 'YC':
      $message = ':ledger: '.$defaultMessage.', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    // red card
    case 'RC':
      $message = ':closed_book: '.$defaultMessage.', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    // conversion
    case 'Con':
      $message = ':thumbsup: '.$defaultMessage.' +'.$post['points'].', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    // missed conversion
    case 'M Con':
      $message = ':thumbsdown: '.$defaultMessage.', '.$language[LANG][3].' '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;

    // match status change
    case 'MS':
      // end of first period
      if ('LHT' == $post['phase'])
      {
        $message = ':toilet: '.$language[LANG][5];

        postToSlack($message, array(
          $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
          $language[LANG][4] => $post['time']['label'],
        ), $db['last_message']);
      }
      // second period is starting
      elseif ('L2' == $post['phase'])
      {
        $message = ':runner: '.$language[LANG][6];

        postToSlack($message, array(
          $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
          $language[LANG][4] => $post['time']['label'],
        ), $db['last_message']);
      }
      // end of second period
      elseif ('LFT' == $post['phase'])
      {
        $message = ':mega: '.$language[LANG][7];

        postToSlack($message, array(
          $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
          $language[LANG][4] => $post['time']['label'],
        ), $db['last_message']);

        $db['live_match'] = 0;
      }
      break;

    // replacement
    case 'Sub On':
      $message = ':arrow_up: '.$defaultMessage.': '.$player;

      postToSlack($message, array(), $db['last_message']);
      break;

    case 'Sub Off':
      $message = ':arrow_down: '.$defaultMessage.': '.$player;

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ), $db['last_message']);
      break;
  }
}

if (isset($post))
{
  $db['last_second'] = $post['time']['secs'];
}

if (isset($message))
{
  $db['last_message'] = $message;
}

file_put_contents($dbFile, json_encode($db));
