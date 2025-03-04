<?php
  /**
   * Database manipulation functions.
   *
   * @author Darcy Driscoll <darcy.driscoll@outlook.com>
   */

  require_once 'string_func.php';
  require_once 'session.php';
  require_once 'db.php';
  require_once 'error_codes.php';
  require_once 'bool_msg.php';

  /**
   * Represents and manages an account in the database.
   */
  class User {
    private $e_codes; // TODO - make static?

    public function __construct() { }

    private function get_code($code) {
      return $this->e_codes->get($code);
    }

    /**
     * Initialise registers and class instances.
     *
     * We do this outside the constructor for now for simpler error handling.
     */
    public function init() {
      try {
        SessionRegister::start();
        DBRegister::connect();
        $this->e_codes = new ErrorCodes();
        return new BoolMsg(true, null);
      } catch (Exception $e) {
        return new BoolMsg(false, $this->get_code('SERVER_ERROR'));
      }
    }

    /**
     * @return string Nickname stored in $_SESSION.
     */
    public function get_nickname() {
      return SessionRegister::get('nickname');
    }

    /**
     * Queries the database to check if the given nickname is taken.
     *
     * @param string $nickname The nickname we want to check
     *
     * @return bool true if the nickname is taken, false if not
     */
    private function is_nickname_taken($nickname) {
      return DBRegister::count_same_nicknames($nickname) > 0;
    }

    /**
     * Add the given nickname to the database.
     *
     * @param string $nickname The nickname we want to add.
     *
     * @return bool true if the nickname was successfully added, false if not.
     */
    private function add_nickname($nickname) {
      if (!$this->is_nickname_taken($nickname)) {
        return DBRegister::insert_nickname($nickname);
      }
      return false;
    }

    /**
     * Validates a given nickname.
     *
     * A 'valid name':
     *  1) composed of characters on a standard American, British,
     *     or Australian QWERTY keyboard;
     *  2) consists of 3-20 characters.
     *
     * @param string @nickname The nickname we want to validate
     *
     * @return bool true if the nickname is valid, false otherwise
     */
    public function is_nickname_valid($nickname) {
      $nick_len = mb_strlen($nickname, 'UTF-8');
      // if length is valid
      if ($nick_len >= 3 && $nick_len <= 20) {
        // if characters are valid
        for ($i = 0; $i < $nick_len; $i++) {
          // TODO - possibly slow - n^2 (b/c php doesn't understand UTF-8 implictly, can't just access characters in UTF-8 string in constant time)
          $nick_i = mb_substr($nickname, $i, 1, 'UTF-8');
          $char_ord = mb_ord($nick_i, 'UTF-8');
          if (($nick_i !== '£') && ($char_ord < 33 || $char_ord > 126)) {
            return false;
          }
        }
        // we've passed the loop, so the nick is valid
        return true;
      }
      return false;
    }

    /**
     * Returns whether the user is signed in.
     *
     * @return bool True if signed in, false if not.
     */
    private function is_signed_in() {
      return !is_null(SessionRegister::get('nickname'));
    }

    /**
     * Returns whether the user is signed in.
     *
     * @return BoolMsg true with a message if signed in, false if not
     */
    public function is_signed_in_msg() {
      try {
        if ($this->is_signed_in()) {
          return new BoolMsg(true, $this->get_code('NICK_ALREADYSIGNEDIN'));
        } else {
          return new BoolMsg(false, $this->get_code('USER_UNAUTHENTICATED'));
        }
      } catch (Exception $e) {
        // server error
        return new BoolMsg(false, $this->get_code('SERVER_ERROR'));
      }
    }

    /**
     * Sign in to the chatroom with a nickname.
     *
     * @param string $nickname The nickname we try to sign in with.
     *
     * @return bool true if we successfully sign in, false if not
     */
    public function sign_in($nickname) {
      try {
        // have we already signed in?
        if ($this->is_signed_in()) {
          return new BoolMsg(true, $this->get_code('NICK_ALREADYSIGNEDIN'));
        }
        // does nickname meet reqs?
        if (!$this->is_nickname_valid($nickname)) {
          return new BoolMsg(false, $this->get_code('NICK_INVALID'));
        }
        $nickname = StringFunc::sanitise_string($nickname);
        // try adding nickname
        if (!$this->add_nickname($nickname)) {
          return new BoolMsg(false, $this->get_code('NICK_INSERTFAIL'));
        }
      } catch (Exception $e) {
        // server error
        return new BoolMsg(false, $this->get_code('SERVER_ERROR'));
      }
      // add nickname
      SessionRegister::set('nickname', $nickname);
      return new BoolMsg(true, $this->get_code('NICK_SUCCESS'));
    }

    /**
     * Generate a message and return it.
     *
     * @return BoolMsg<ChatMessage>|BoolMsg<String> depending on whether the
     *  message generation succeeds.
     */
    public function generate_message() {
      try {
        // make sure we're signed in already
        if (!$this->is_signed_in()) {
          return new BoolMsg(false, $this->get_code('USER_UNAUTHENTICATED'));
        }
        // try adding a message
        $message = DBRegister::add_new_message($this->get_nickname());
        return new BoolMsg(true, $message);
      } catch (Exception $e) {
        // server error
        return new BoolMsg(false, $this->get_code('SERVER_ERROR'));
      }
    }

    /**
     * Try to retrieve a new message from the database.
     *
     * @param int $last_id The id of the last retrieved message.
     *
     * @return BoolMsg<string>|BoolMsg<ChatMessage> depending on whether there's
     *  a new message in the database.
     */
    public function get_new_message() {
      try {
        // make sure we're signed in already
        if (!$this->is_signed_in()) {
          return new BoolMsg(false, $this->get_code('USER_UNAUTHENTICATED'));
        }
        // have we waited long enough since the previous request?
        $timeout_s = 1;
        $current_time = time();
        if (isset($_SESSION['message_get_time'])) {
          $last_time = $_SESSION['message_get_time'];
          $_SESSION['message_get_time'] = $current_time; // update
          if ($current_time - $last_time < $timeout_s) {
            return new BoolMsg(false, $this->get_code('TIMEOUT'));
          }
        } else {
          // first time setting the 'last time' value
          $_SESSION['message_get_time'] = $current_time;
        }
        // get id of last-retrieved message
        $last_id = 'null';
        if (isset($_SESSION['last_id'])) {
          $last_id = $_SESSION['last_id'];
        }
        // try getting a new message
        $new_message = DBRegister::get_new_message($last_id);
        if (!$new_message->bool) {
          return new BoolMsg(false, $this->e_codes->get('NO_NEW_MESSAGES'));
        } else {
          $_SESSION['last_id'] = $new_message->msg->id;
          return $new_message;
        }
      } catch (Exception $e) {
        // server error
        return new BoolMsg(false, $this->get_code('SERVER_ERROR'));
      }
    }
  }

 ?>
