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
  ),
  'en' => array(
    'The match between',
    'has started',
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

function postToSlack($text, $attachments_text = '')
{
  // var_dump($text);
  // return;

  $slackUrl = 'https://slack.com/api/chat.postMessage?token='.SLACK_TOKEN.
    '&channel='.urlencode(SLACK_CHANNEL).
    '&username='.urlencode(SLACK_BOT_NAME).
    '&icon_url='.SLACK_BOT_AVATAR.
    '&unfurl_links=1&parse=full'.
    '&text='.urlencode($text);

  if ($attachments_text)
  {
    $slackUrl .= '&attachments='.urlencode('[{"text": "'.$attachments_text.'"}]');
  }

  var_dump(getUrl($slackUrl));
}

$dbFile = './rugbyWorldCupDB.json';

$db = json_decode(file_get_contents($dbFile), true);
$response = json_decode(getUrl('http://cmsapi.pulselive.com/rugby/event/1238/schedule?language='.LANG), true);

if (!isset($response['matches']))
{
  var_dump('matches not good');
  die();
}

// find live matches
foreach ($response['matches'] as $match)
{
  // status: U, LT1, L1, LHT, L2, C
  if ('U' !== $match['status'])
  {
    // get blog id
    $blog = json_decode(getUrl('http://cmsapi.pulselive.com/liveblog/worldrugby/'.LANG.'/?references=RUGBY_MATCH:'.$match['matchId']), true);

    if (!in_array($blog['content'][0]['header']['liveBlogId'], $db['live_matches']))
    {
      $db['live_matches'][] = $blog['content'][0]['header']['liveBlogId'];
      $db[$blog['content'][0]['header']['liveBlogId']] = array('last_post_id' => 0);

      // notify slack & save data
      postToSlack(':zap: '.$language[LANG][0].' '.$match['teams'][0]['name'].' / '.$match['teams'][1]['name'].' '.$language[LANG][1].'! http://www.rugbyworldcup.com/match/'.$match['matchId'].'#blog');
      file_put_contents($dbFile, json_encode($db));
      return;
    }
  }
}

// post update on live matches
foreach ($db['live_matches'] as $key => $liveMatch)
{
  // $response = json_decode(getUrl('http://cmsapi.pulselive.com/liveblog/worldrugby/'.$liveMatch.'/'.LANG.'/newerThan/'.$db[$liveMatch]['last_update'].'?client=slack-bot'), true);
  $response = json_decode(getUrl('http://cmsapi.pulselive.com/liveblog/worldrugby/'.$liveMatch.'/'.LANG.'/?direction=descending&client=slack-bot'), true);

  if (!isset($response['entries']))
  {
    var_dump('entries not good');
    continue;
  }

  // match isn't live
  if ('ACTIVE' !== $response['status'])
  {
    unset($db['live_matches'][$key]);
    unset($db[$liveMatch]);
    continue;
  }

  $posts = $response['entries'];

  // sort posts by "date"
  krsort($posts);

  foreach ($posts as $post)
  {
    if ($post['id'] <= $db[$liveMatch]['last_post_id'])
    {
      continue;
    }

    $title = $post['title'];
    $comment = str_replace('"', '', trim(strip_tags($post['comment'])));

    switch ($post['icon']['name'])
    {
      case 'Text':
      case 'Match Event':
        postToSlack($title, $comment);
        break;

      case 'Tweet':
        preg_match('/https:\/\/twitter\.com\/([a-z0-9\-\_]+)\/status\/([0-9]+)/i', $post['comment'], $matches);

        if (isset($matches[0]))
        {
          postToSlack(':bird: '.$title.': '.$matches[0], $comment);
        }
        else
        {
          postToSlack(':bird: '.$title, $comment);
        }
        break;

      case 'Photo':
        preg_match('/http:\/\/(.*)\.jpg/i', $post['comment'], $matches);

        if (isset($matches[0]))
        {
          postToSlack(':camera: '.$title.': '.$matches[0], $comment);
        }
        else
        {
          postToSlack(':camera: '.$title, $comment);
        }
        break;

      case 'Stat':
        postToSlack(':bar_chart: '.$title, $comment);
        break;

      case 'Try':
        if ('' !== $post['properties']['score'])
        {
          postToSlack(':rugby_football: '.$post['properties']['score'].' – '.$title, $comment);
        }
        else
        {
          postToSlack(':loudspeaker: '.$title, $comment);
        }
        break;

      case 'Breaking News':
      case 'Big Hit!':
        if ('' !== $post['properties']['score'])
        {
          postToSlack(':rugby_football: '.$post['properties']['score'].' – '.$title, $comment);
        }
        else
        {
          postToSlack(':loudspeaker: '.$title, $comment);
        }

        break;

      case 'Half Time':
        postToSlack(':toilet: '.$title, $comment);
        break;

      case 'Full Time':
        postToSlack(':no_good: '.$post['properties']['score'].' – '.$title, $comment);
        break;
    }
  }

  if (isset($db[$liveMatch]) && isset($post))
  {
    $db[$liveMatch]['last_post_id'] = $post['id'];
  }
}

file_put_contents($dbFile, json_encode($db));
