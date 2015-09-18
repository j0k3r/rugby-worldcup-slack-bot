# Rugby World Cup Slack Bot

Rugby World Cup Slack Bot will notify a Slack channel/group for every match during the 2015 Rugby World Cup in England.

It uses the "unofficial" World Rugby json API (the one used for their mobile apps).

It will post a message :
  - when a match starts
  - and of course, for every point

### Preview

Here is a preview of the England vs Fiji match.

![rugby-worldcup-slack-bot sample](http://i.imgur.com/ucMTQrq.png)

### Requirements

  - PHP >= 5.3
  - You need a token from Slack:
    - Jump at https://api.slack.com/#auth (you have to login)
    - and you will find your token.

### Installation

  - Clone this repo
  - Set up a cron to run every minute:

  ````
  * * * * * cd /path/to/folder && php rugbyWorldCupNotifier.php >> rugbyWorldCupNotifier.log
  ````

### Side notes

The code is ugly but it works.

Everything is posted in french, but feel free to fork and use your own language. FYI, World Rugby API can provide text in en/fr/es/ja.
