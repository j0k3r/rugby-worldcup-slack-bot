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
    'Joueur',
    'Temps',
  ),
  'en' => array(
    'The match between',
    'has started',
    'Score',
    'Player',
    'Time',
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

function postToSlack($text, $attachments_fields = array())
{
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
    );

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
  // MS group time is always bad ...
  if ($post['time']['secs'] < $db['last_second'] && $post['group'] != 'MS')
  {
    continue;
  }

  // only notify this kind of event
  if (!in_array($post['group'], array('Pen', 'M Pen', 'Try', 'YC', 'Con', 'MS', 'Sub On')))
  {
    continue;
  }

  $message = $post['typeLabel'].' ('.$response['match']['teams'][$post['teamIndex']]['name'].')';

  switch ($post['group'])
  {
    // penality
    case 'Pen':
      $message = ':thumbsup::skin-tone-2: '.$message.' +'.$post['points'].', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    // missed penality
    case 'M Pen':
      $message = ':thumbsdown::skin-tone-2: '.$message.', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    case 'Try':
      $message = ':rugby_football: '.$message.' +'.$post['points'].', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    // yellow card
    case 'YC':
      $mesage = ':ledger: '.$message.', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    // red card
    case 'RC':
      $mesage = ':closed_book: '.$message.', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    // conversion
    case 'Con':
      $message = ':thumbsup::skin-tone-2: '.$message.' +'.$post['points'].', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    // missed conversion
    case 'M Con':
      $message = ':thumbsdown::skin-tone-2: '.$message.', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;

    // match status change
    case 'MS':
      // end of first period
      if ('LHT' == $post['phase'])
      {
        $message = ':toilet: '.$message;

        postToSlack($message, array(
          $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
          $language[LANG][4] => $post['time']['label'],
        ));
      }
      // second period is starting
      elseif ('L2' == $post['phase'])
      {
        $message = ':runner: '.$message;

        postToSlack($message, array(
          $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
          $language[LANG][4] => $post['time']['label'],
        ));
      }
      // end of second period
      elseif ('LFT' == $post['phase'])
      {
        $message = ':mega: '.$message;

        postToSlack($message, array(
          $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
          $language[LANG][4] => $post['time']['label'],
        ));

        $db['live_match'] = 0;
      }
      break;

    // replacement
    case 'Sub On':
      $mesage = ':arrows_clockwise: '.$message.', '.$post['playerId'];

      postToSlack($message, array(
        $language[LANG][2] => $response['match']['scores'][0].' - '.$response['match']['scores'][1],
        'In' => $post['playerId'],
        'Out' => $post['playerId'],
        $language[LANG][4] => $post['time']['label'],
      ));
      break;
  }
}

if (isset($post))
{
  $db['last_second'] = $post['time']['secs'];
}

file_put_contents($dbFile, json_encode($db));
