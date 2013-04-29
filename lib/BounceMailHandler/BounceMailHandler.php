<?php
/**
 * Bounce Mail Handler (formerly known as BMH and PHPMailer-BMH)
 *
 * @copyright 2008-2009 Andry Prevost. All Rights Reserved.
 * @copyright 2011-2012 Anthon Pang.
 *
 * @license GPL
 *
 * @package BounceMailHandler
 *
 * @author Andy Prevost <andy.prevost@worxteam.com>
 * @author Anthon Pang <apang@softwaredevelopment.ca>
 */
namespace BounceMailHandler;

require_once(__DIR__ . '/phpmailer-bmh_rules.php');

/**
 * BounceMailHandler class
 *
 * BounceMailHandler is a PHP program to check your IMAP/POP3 inbox and
 * delete all 'hard' bounced emails. It features a callback function where
 * you can create a custom action. This provides you the ability to write
 * a script to match your database records and either set inactive or
 * delete records with email addresses that match the 'hard' bounce results.
 *
 * @package BounceMailHandler
 */
class BounceMailHandler
{
    const VERBOSE_QUIET = 0;  // suppress output
    const VERBOSE_SIMPLE = 1; // simple report
    const VERBOSE_REPORT = 2; // detailed report
    const VERBOSE_DEBUG = 3;  // detailed report plus debug info

    /**
     * Holds Bounce Mail Handler version.
     *
     * @var string
     */
    private $version = "5.3-dev";

    /**
     * Mail server
     *
     * @var string
     */
    public $mailhost = 'localhost';

    /**
     * The username of mailbox
     *
     * @var string
     */
    public $mailboxUserName;

    /**
     * The password needed to access mailbox
     *
     * @var string
     */
    public $mailboxPassword;

    /**
     * The last error msg
     *
     * @var string
     */
    public $errorMessage;

    /**
     * Maximum limit messages processed in one batch
     *
     * @var int
     */
    public $maxMessages = 3000;

  /**
   * Callback Action function name
   * the function that handles the bounce mail. Parameters:
   *   int     $msgnum        the message number returned by Bounce Mail Handler
   *   string  $bounce_type   the bounce type: 'antispam','autoreply','concurrent','content_reject','command_reject','internal_error','defer','delayed'        => array('remove'=>0,'bounce_type'=>'temporary'),'dns_loop','dns_unknown','full','inactive','latin_only','other','oversize','outofoffice','unknown','unrecognized','user_reject','warning'
   *   string  $email         the target email address
   *   string  $subject       the subject, ignore now
   *   string  $xheader       the XBounceHeader from the mail
   *   1 or 0  $remove        delete status, 0 is not deleted, 1 is deleted
   *   string  $rule_no       bounce mail detect rule no.
   *   string  $rule_cat      bounce mail detect rule category
   *   int     $totalFetched  total number of messages in the mailbox
   *
   * @var mixed
   */
  public $actionFunction = 'callbackAction';

  /**
   * Internal variable
   * The resource handler for the opened mailbox (POP3/IMAP/NNTP/etc.)
   *
   * @var object
   */
  private $mailboxLink = false;

  /**
   * Test mode, if true will not delete messages
   *
   * @var boolean
   */
  public $testMode = false;

  /**
   * Purge the unknown messages (or not)
   *
   * @var boolean
   */
  public $purgeUnprocessed = false;

  /**
   * Control the debug output, default is VERBOSE_SIMPLE
   *
   * @var int
   */
  public $verbose = self::VERBOSE_SIMPLE;

  /**
   * control the failed DSN rules output
   *
   * @var boolean
   */
  public $debugDsnRule = false;

  /**
   * control the failed BODY rules output
   *
   * @var boolean
   */
  public $debugBodyRule = false;

  /**
   * Control the method to process the mail header
   * if set true, uses the imap_fetchstructure function
   * otherwise, detect message type directly from headers,
   * a bit faster than imap_fetchstructure function and take less resources.
   * however - the difference is negligible
   *
   * @var boolean
   */
  public $useFetchstructure = true;

  /**
   * If disableDelete is equal to true, it will disable the delete function
   *
   * @var boolean
   */
  public $disableDelete = false;

  /**
   * Defines new line ending
   *
   * @var string
   */
  public $bmhNewLine = "<br />\n";

  /**
   * Defines port number, default is '143', other common choices are '110' (pop3), '993' (gmail)
   *
   * @var integer
   */
  public $port = 143;

  /**
   * Defines service, default is 'imap', choice includes 'pop3'
   *
   * @var string
   */
  public $service = 'imap';

  /**
   * Defines service option, default is 'notls', other choices are 'tls', 'ssl'
   *
   * @var string
   */
  public $serviceOption = 'notls';

  /**
   * Mailbox type, default is 'INBOX', other choices are (Tasks, Spam, Replies, etc.)
   *
   * @var string
   */
  public $boxname = 'INBOX';

  /**
   * Determines if soft bounces will be moved to another mailbox folder
   *
   * @var boolean
   */
  public $moveSoft = false;

  /**
   * Mailbox folder to move soft bounces to, default is 'soft'
   *
   * @var string
   */
  public $softMailbox = 'INBOX.soft';

  /**
   * Determines if hard bounces will be moved to another mailbox folder
   * NOTE: If true, this will disable delete and perform a move operation instead
   *
   * @var boolean
   */
  public $moveHard = false;

  /**
   * Mailbox folder to move hard bounces to, default is 'hard'
   *
   * @var string
   */
  public $hardMailbox = 'INBOX.hard';

  /**
   * Deletes messages globally prior to date in variable
   * NOTE: excludes any message folder that includes 'sent' in mailbox name
   * format is same as MySQL: 'yyyy-mm-dd'
   * if variable is blank, will not process global delete
   *
   * @var string
   */
  public $deleteMsgDate = '';

    /**
     * Get version
     *
     * @return string
     */
     public function getVersion()
     {
         return $this->version;
     }

    /**
     * Output additional msg for debug
     *
     * @param string $msg          if not given, output the last error msg
     * @param string $verboseLevel the output level of this message
     */
    public function output($msg = false, $verboseLevel = BounceMailHandler::VERBOSE_SIMPLE)
    {
        if ($this->verbose >= $verboseLevel) {
            if (empty($msg)) {
                echo $this->errorMessage . $this->bmhNewLine;
            } else {
                echo $msg . $this->bmhNewLine;
            }
        }
    }

    /**
     * Open a mail box
     *
     * @return boolean
     */
    public function openMailbox()
    {
        // before starting the processing, let's check the delete flag and do global deletes if true
        if (trim($this->deleteMsgDate) != '') {
            echo "processing global delete based on date of " . $this->deleteMsgDate . "<br />";
            $this->globalDelete($nameRaw);
        }

        // disable move operations if server is Gmail ... Gmail does not support mailbox creation
        if (stristr($this->mailhost, 'gmail')) {
            $this->moveSoft = false;
            $this->moveHard = false;
        }

        $port = $this->port . '/' . $this->service . '/' . $this->serviceOption;

        set_time_limit(6000);

        if (!$this->testMode) {
            $this->mailboxLink = imap_open("{".$this->mailhost.":".$port."}" . $this->boxname, $this->mailboxUserName, $this->mailboxPassword, CL_EXPUNGE | ($this->testMode ? OP_READONLY : 0));
        } else {
            $this->mailboxLink = imap_open("{".$this->mailhost.":".$port."}" . $this->boxname, $this->mailboxUserName, $this->mailboxPassword, ($this->testMode ? OP_READONLY : 0));
        }

        if (!$this->mailboxLink) {
            $this->errorMessage = 'Cannot create ' . $this->service . ' connection to ' . $this->mailhost . $this->bmhNewLine . 'Error MSG: ' . imap_last_error();
            $this->output();

            return false;
        } else {
            $this->output('Connected to: ' . $this->mailhost . ' (' . $this->mailboxUserName . ')');

            return true;
        }
    }

    /**
     * Open a mail box in local file system
     *
     * @param string $filePath The local mailbox file path
     *
     * @return boolean
     */
    public function openLocal($filePath)
    {
        set_time_limit(6000);

        if (!$this->testMode) {
            $this->mailboxLink = imap_open($filePath, '', '', CL_EXPUNGE | ($this->testMode ? OP_READONLY : 0));
        } else {
            $this->mailboxLink = imap_open($filePath, '', '', ($this->testMode ? OP_READONLY : 0));
        }

        if (!$this->mailboxLink) {
            $this->errorMessage = 'Cannot open the mailbox file to ' . $filePath . $this->bmhNewLine . 'Error MSG: ' . imap_last_error();
            $this->output();

            return false;
        } else {
            $this->output('Opened ' . $filePath);

            return true;
        }
    }

    /**
     * Process the messages in a mailbox
     *
     * @param string $max maximum limit messages processed in one batch, if not given uses the property $maxMessages
     *
     * @return boolean
     */
    public function processMailbox($max = false)
    {
        if (empty($this->actionFunction) || !is_callable($this->actionFunction)) {
            $this->errorMessage = 'Action function not found!';
            $this->output();

            return false;
        }

        if ($this->moveHard && ($this->disableDelete === false)) {
            $this->disableDelete = true;
        }

        if (!empty($max)) {
            $this->maxMessages = $max;
        }

        // initialize counters
        $totalCount       = imap_num_msg($this->mailboxLink);
        $fetchedCount     = $totalCount;
        $processedCount   = 0;
        $unprocessedCount = 0;
        $deletedCount     = 0;
        $movedCount       = 0;
        $this->output('Total: ' . $totalCount . ' messages ');

        // proccess maximum number of messages
        if ($fetchedCount > $this->maxMessages) {
            $fetchedCount = $this->maxMessages;
            $this->output('Processing first ' . $fetchedCount . ' messages ');
        }

        if ($this->testMode) {
            $this->output('Running in test mode, not deleting messages from mailbox<br />');
        } else {
            if ($this->disableDelete) {
                if ($this->moveHard) {
                    $this->output('Running in move mode<br />');
                } else {
                    $this->output('Running in disableDelete mode, not deleting messages from mailbox<br />');
                }
            } else {
                $this->output('Processed messages will be deleted from mailbox<br />');
            }
        }

        $outpout_array = array();
        
        for ($x = 1; $x <= $fetchedCount; $x++) {
/*
            $this->output($x . ":", self::VERBOSE_REPORT);

            if ($x % 10 == 0) {
                $this->output('.', self::VERBOSE_SIMPLE);
            }
*/
            
            // fetch the messages one at a time
            if ($this->useFetchstructure) {
                $structure = imap_fetchstructure($this->mailboxLink, $x);

                if ($structure->type == 1 && $structure->ifsubtype && $structure->subtype == 'REPORT' && $structure->ifparameters && $this->isParameter($structure->parameters, 'REPORT-TYPE', 'delivery-status')) {
                    $processed = $this->processBounce($x, 'DSN', $totalCount);
                    $outpout_array[] = $processed;
                } else {
                    // not standard DSN msg
                    $this->output('Msg #' .  $x . ' is not a standard DSN message', self::VERBOSE_REPORT);

                    if ($this->debugBodyRule) {
                        if ($structure->ifdescription) {
                            $this->output("  Content-Type : {$structure->description}", self::VERBOSE_DEBUG);
                        } else {
                            $this->output("  Content-Type : unsupported", self::VERBOSE_DEBUG);
                        }
                    }

                    $processed = $this->processBounce($x, 'BODY', $totalCount);
                    $outpout_array[] = $processed;
                }
            } else {
                $header = imap_fetchheader($this->mailboxLink, $x);

                // Could be multi-line, if the new line begins with SPACE or HTAB
                if (preg_match("/Content-Type:((?:[^\n]|\n[\t ])+)(?:\n[^\t ]|$)/is", $header, $match)) {
                    if (preg_match("/multipart\/report/is", $match[1]) && preg_match("/report-type=[\"']?delivery-status[\"']?/is", $match[1])) {
                        // standard DSN msg
                        $processed = $this->processBounce($x, 'DSN', $totalCount);
                        $outpout_array[] = $processed;
                    } else {
                        // not standard DSN msg
                        $this->output('Msg #' .  $x . ' is not a standard DSN message', self::VERBOSE_REPORT);

                        if ($this->debugBodyRule) {
                            $this->output("  Content-Type : {$match[1]}", self::VERBOSE_DEBUG);
                        }

                        $processed = $this->processBounce($x, 'BODY', $totalCount);
                        $outpout_array[] = $processed;
                    }
                } else {
                    // didn't get content-type header
                    $this->output('Msg #' .  $x . ' is not a well-formatted MIME mail, missing Content-Type', self::VERBOSE_REPORT);

                    if ($this->debugBodyRule) {
                        $this->output('  Headers: ' . $this->bmhNewLine . $header . $this->bmhNewLine, self::VERBOSE_DEBUG);
                    }

                    $processed = $this->processBounce($x, 'BODY', $totalCount);
                    $outpout_array[] = $processed;
                }
            }

            $deleteFlag[$x] = false;
            $moveFlag[$x]   = false;

            if ($processed) {
                $processedCount++;

                if ( ! $this->disableDelete) {
                    // delete the bounce if not in disableDelete mode
                    if ( ! $this->testMode) {
                        @imap_delete($this->mailboxLink, $x);
                    }

                    $deleteFlag[$x] = true;
                    $deletedCount++;
                } elseif ($this->moveHard) {
                    // check if the move directory exists, if not create it
                    if ( ! $this->testMode) {
                        $this->mailboxExist($this->hardMailbox);
                    }

                    // move the message
                    if ( ! $this->testMode) {
                        @imap_mail_move($this->mailboxLink, $x, $this->hardMailbox);
                    }

                    $moveFlag[$x] = true;
                    $movedCount++;
                } elseif ($this->moveSoft) {
                    // check if the move directory exists, if not create it
                    if ( ! $this->testMode) {
                        $this->mailboxExist($this->softMailbox);
                    }

                    // move the message
                    if ( ! $this->testMode) {
                        @imap_mail_move($this->mailboxLink, $x, $this->softMailbox);
                    }

                    $moveFlag[$x] = true;
                    $movedCount++;
                }
            } else {
                // not processed
                $unprocessedCount++;
                if ( ! $this->disableDelete && $this->purgeUnprocessed) {
                    // delete this bounce if not in disableDelete mode, and the flag BOUNCE_PURGE_UNPROCESSED is set
                    if ( ! $this->testMode) {
                        @imap_delete($this->mailboxLink, $x);
                    }

                    $deleteFlag[$x] = true;
                    $deletedCount++;
                }
            }

            flush();
        }

        $this->output($this->bmhNewLine . 'Closing mailbox, and purging messages');

        imap_close($this->mailboxLink);

        $this->output('Read: ' . $fetchedCount . ' messages');
        $this->output($processedCount . ' action taken');
        $this->output($unprocessedCount . ' no action taken');
        $this->output($deletedCount . ' messages deleted');
        $this->output($movedCount . ' messages moved');

        return $outpout_array;
    }

    /**
     * Function to determine if a particular value is found in a imap_fetchstructure key
     *
     * @param array  $currParameters imap_fetstructure parameters
     * @param string $varKey         imap_fetstructure key
     * @param string $varValue       value to check for
     *
     * @return boolean
     */
    public function isParameter($currParameters, $varKey, $varValue)
    {
        foreach ($currParameters as $object) {
            if ($object->attribute == $varKey) {
                if ($object->value == $varValue) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Function to process each individual message
     *
     * @param int    $pos          message number
     * @param string $type         DNS or BODY type
     * @param string $totalFetched total number of messages in mailbox
     *
     * @return boolean
     */
    public function processBounce($pos, $type, $totalFetched)
    {
        $header  = imap_header($this->mailboxLink, $pos);
        $subject = strip_tags($header->subject);
        $body    = '';

        if ($type == 'DSN') {
            // first part of DSN (Delivery Status Notification), human-readable explanation
            $dsnMsg = imap_fetchbody($this->mailboxLink, $pos, "1");
            $dsnMsgStructure = imap_bodystruct($this->mailboxLink, $pos, "1");

            if ($dsnMsgStructure->encoding == 4) {
                $dsnMsg = quoted_printable_decode($dsnMsg);
            } elseif ($dsnMsgStructure->encoding == 3) {
                $dsnMsg = base64_decode($dsnMsg);
            }

            // second part of DSN (Delivery Status Notification), delivery-status
            $dsnReport = imap_fetchbody($this->mailboxLink, $pos, "2");

            // process bounces by rules
            $result = bmhDSNRules($dsnMsg, $dsnReport, $this->debugDsnRule);
        } elseif ($type == 'BODY') {
            $structure = imap_fetchstructure($this->mailboxLink, $pos);

            switch ($structure->type) {
                case 0: // Content-type = text
                    $body = imap_fetchbody($this->mailboxLink, $pos, "1");
                    $result = bmhBodyRules($body, $structure, $this->debugBodyRule);
                    break;

                case 1: // Content-type = multipart
                    $body = imap_fetchbody($this->mailboxLink, $pos, "1");

                    // Detect encoding and decode - only base64
                    if ($structure->parts[0]->encoding == 4) {
                        $body = quoted_printable_decode($body);
                    } elseif ($structure->parts[0]->encoding == 3) {
                        $body = base64_decode($body);
                    }

                    $result = bmhBodyRules($body, $structure, $this->debugBodyRule);
                    break;

                case 2: // Content-type = message
                    $body = imap_body($this->mailboxLink, $pos);

                    if ($structure->encoding == 4) {
                        $body = quoted_printable_decode($body);
                    } elseif ($structure->encoding == 3) {
                        $body = base64_decode($body);
                    }

                    $body = substr($body, 0, 1000);
                    $result = bmhBodyRules($body, $structure, $this->debugBodyRule);
                    break;

                default: // unsupport Content-type
                    $this->output('Msg #' . $pos . ' is unsupported Content-Type:' . $structure->type, self::VERBOSE_REPORT);

                    return false;
            }
        } else {
            // internal error
            $this->errorMessage = 'Internal Error: unknown type';

            return false;
        }

        $email      = $result['email'];
        $bounceType = $result['bounce_type'];

        if ($this->moveHard && $result['remove'] == 1) {
            $remove = 'moved (hard)';
        } elseif ($this->moveSoft && $result['remove'] == 1) {
            $remove = 'moved (soft)';
        } elseif ($this->disableDelete) {
            $remove = 0;
        } else {
            $remove = $result['remove'];
        }

        $ruleNumber   = $result['rule_no'];
        $ruleCategory = $result['rule_cat'];
        $xheader      = false;

        if ($ruleNumber === '0000') {
            // unrecognized
            if (trim($email) == '') {
                $email = $header->fromaddress;
            }

            if ($this->testMode) {
                $this->output('Match: ' . $ruleNumber . ':' . $ruleCategory . '; ' . $bounceType . '; ' . $email);
            } else {
                // code below will use the Callback function, but return no value
                $params = array($pos, $bounceType, $email, $subject, $header, $remove, $ruleNumber, $ruleCategory, $totalFetched, $body);
                call_user_func_array($this->actionFunction, $params);
            }
        } else {
            
        $output_array = array(
            'ruleNumber' => $ruleNumber,
            'ruleCategory' => $ruleCategory,
            'bounceType' => $bounceType,
            'email' => $email
        );
        return $output_array;
        
            // match rule, do bounce action
            if ($this->testMode) {
                $this->output('Match: ' . $ruleNumber . ':' . $ruleCategory . '; ' . $bounceType . '; ' . $email);

                return true;
            } else {
                $params = array($pos, $bounceType, $email, $subject, $xheader, $remove, $ruleNumber, $ruleCategory, $totalFetched, $body);

                return call_user_func_array($this->actionFunction, $params);
            }
            
            
        }
    }

    /**
     * Function to check if a mailbox exists
     * - if not found, it will create it
     *
     * @param string  $mailbox the mailbox name, must be in 'INBOX.checkmailbox' format
     * @param boolean $create  whether or not to create the checkmailbox if not found, defaults to true
     *
     * @return boolean
     */
    public function mailboxExist($mailbox, $create = true)
    {
        if (trim($mailbox) == '' || ! strstr($mailbox, ' INBOX.')) {
            // this is a critical error with either the mailbox name blank or an invalid mailbox name
            // need to stop processing and exit at this point
            echo "Invalid mailbox name for move operation. Cannot continue.<br />\n";
            echo "TIP: the mailbox you want to move the message to must include 'INBOX.' at the start.<br />\n";
            exit();
        }

        $port = $this->port . '/' . $this->service . '/' . $this->serviceOption;
        $mbox = imap_open('{'.$this->mailhost.":".$port.'}', $this->mailboxUserName, $this->mailboxPassword, OP_HALFOPEN);
        $list = imap_getmailboxes($mbox, '{'.$this->mailhost.":".$port.'}', "*");
        $mailboxFound = false;

        if (is_array($list)) {
            foreach ($list as $key => $val) {
                // get the mailbox name only
                $nameArr = split('}', imap_utf7_decode($val->name));
                $nameRaw = $nameArr[count($nameArr)-1];
                if ($mailbox == $nameRaw) {
                    $mailboxFound = true;
                }
            }

            if (($mailboxFound === false) && $create) {
                @imap_createmailbox($mbox, imap_utf7_encode('{'.$this->mailhost.":".$port.'}' . $mailbox));
                imap_close($mbox);

                return true;
            } else {
                imap_close($mbox);

                return false;
            }
        } else {
            imap_close($mbox);

            return false;
        }
    }

    /**
     * Function to delete messages in a mailbox, based on date
     * NOTE: this is global ... will affect all mailboxes except any that have 'sent' in the mailbox name
     */
    public function globalDelete()
    {
        $dateArr = split('-', $this->deleteMsgDate); // date format is yyyy-mm-dd
        $delDate = mktime(0, 0, 0, $dateArr[1], $dateArr[2], $dateArr[0]);

        $port  = $this->port . '/' . $this->service . '/' . $this->serviceOption;
        $mboxt = imap_open('{'.$this->mailhost.":".$port.'}', $this->mailboxUserName, $this->mailboxPassword, OP_HALFOPEN);
        $list  = imap_getmailboxes($mboxt, '{'.$this->mailhost.":".$port.'}', "*");
        $mailboxFound = false;

        if (is_array($list)) {
            foreach ($list as $key => $val) {
                // get the mailbox name only
                $nameArr = split('}', imap_utf7_decode($val->name));
                $nameRaw = $nameArr[count($nameArr)-1];

                if ( ! stristr($nameRaw, 'sent')) {
                    $mboxd = imap_open('{'.$this->mailhost.":".$port.'}'.$nameRaw, $this->mailboxUserName, $this->mailboxPassword, CL_EXPUNGE);
                    $messages = imap_sort($mboxd, SORTDATE, 0);
                    $i = 0;
                    $check = imap_mailboxmsginfo($mboxd);

                    foreach ($messages as $message) {
                        $header = imap_header($mboxd, $message);
                        $fdate  = date("F j, Y", $header->udate);

                        // purge if prior to global delete date
                        if ($header->udate < $delDate) {
                            imap_delete($mboxd, $message);
                        }
                        $i++;
                    }

                    imap_expunge($mboxd);
                    imap_close($mboxd);
                }
            }
        }
    }
}
