<?php

/**
 * Push aka Instant Updates
 *
 * @author Aleksander Machniak <alec@alec.pl>
 *
 * Copyright (C) 2010-2019 The Roundcube Dev Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see http://www.gnu.org/licenses/.
 */

/**
 * Parser of push notifications as of RFC5423
 * This format is used e.g. by Cyrus IMAP
 */
class push_parser_notify
{
    public function __construct()
    {
        // $this->service = push_service::get_instance();
    }

    public function parse($data)
    {
        if (!is_array($data) || empty($data) || !isset($data['event'])) {
            return;
        }

        // Get event name (remove non-standard prefixes)
        $event = str_replace('vnd.cmu.', '', $data['event']);
        $data['event'] = $event;

        // Parse data
        $common = $this->commonProps($data);
        $result = $this->{"event$event"}($data);

        if (is_array($result)) {
            return array_merge($common, $result);
        }
    }

    public function __call($name, $arguments)
    {
        // Here we end up with unknown/undefined events
    }

    protected function commonProps($data)
    {
        $uri = $this->parseURI($data['uri']);

        $result = array(
            'event'       => $data['event'],
            'service'     => $data['service'],
            'uidset'      => $data['uidset'],
            'exists'      => isset($data['messages']) ? intval($data['messages']) : null,
            'folder_user' => $uri['user'] ?: $data['user'],
            'folder_name' => $uri['folder'],
        );

        if (isset($data['oldMailboxID'])) {
            $uri = $this->parseURI($data['oldMailboxID']);

            $result['old_folder_user'] = $uri['user'] ?: $data['user'];
            $result['old_folder_name'] = $uri['folder'];
        }

        if (isset($data['vnd.cmu.unseenMessages'])) {
            $result['unseen'] = intval($data['vnd.cmu.unseenMessages']);
        }

        return $result;
    }

    protected function eventAclChange($data)
    {
        // Not implemented
    }

    protected function eventFlagsClear($data)
    {
        return array(
            'flags' => explode(' ', $data['flagNames']),
        );
    }

    protected function eventFlagsSet($data)
    {
        return array(
            'flags' => explode(' ', $data['flagNames']),
        );
    }

    protected function eventLogin($data)
    {
        // Not implemented
    }

    protected function eventLogout($data)
    {
        // Not implemented
    }

    protected function eventMailboxCreate($data)
    {
        return array();
    }

    protected function eventMailboxDelete($data)
    {
        return array();
    }

    protected function eventMailboxRename($data)
    {
        return array();
    }

    protected function eventMailboxSubscribe($data)
    {
        return array();
    }

    protected function eventMailboxUnSubscribe($data)
    {
        return array();
    }

    protected function eventMessageAppend($data)
    {
        return array();
    }

    protected function eventMessageCopy($data)
    {
        return array(
            'old_uidset' => $data['vnd.cmu.oldUidset'],
        );
    }

    protected function eventMessageExpire($data)
    {
        // Not implemented
    }

    protected function eventMessageExpunge($data)
    {
        return array();
    }

    protected function eventMessageMove($data)
    {
        // this is non-standard event used in Cyrus IMAP
        return $this->eventMessageCopy($data);
    }

    protected function eventMessageNew($data)
    {
        // Add some props that might be useful if we want to display
        // new message notification with message subject, etc.
        // Maybe we should talk with newmail_notifier plugin
        $headers = array();

        // Note: messageHeaders is not defined in RFC5423, but used by Cyrus.
        // Other properties does not include Subject header
        foreach ((array) $data['messageHeaders'] as $uid => $h) {
            $headers[$uid] = array(
                'subject' => $h['Subject'],
                'from'    => $h['From'],
            );
        }

        return array(
            'headers' => $headers,
        );
    }

    protected function eventMessageRead($data)
    {
        // This is equivalent of FlagsSet with \Seen flag
        // so let's imitate it for simplicity
        return array(
            'event' => 'FlagsSet',
            'flags' => array('\\Seen'),
        );
    }

    protected function eventMessageTrash($data)
    {
        // This is equivalent of FlagsSet with \Deleted flag
        // so let's imitate it for simplicity
        return array(
            'event' => 'FlagsSet',
            'flags' => array('\\Deleted'),
        );
    }

    protected function eventQuotaChange($data)
    {
        return array(
            'quota'    => $data['diskQuota'],
            'used'     => $data['diskUsed'],
            'messages' => $data['maxMessages'],
        );
    }

    protected function eventQuotaExceed($data)
    {
        // Not implemented
    }

    protected function eventQuotaWithin($data)
    {
        return $this->eventQuotaChange($data);
    }

    /**
     * Parse imap folder URI to extract user and folder name
     */
    protected function parseURI($uri)
    {
        $_uri = parse_url($uri);

        list($path, $args) = explode(';', $_uri['path'], 2);

        $path   = ltrim($path, '/');
        $params = array();

        // Standard: "imap://test%40example.org@imap.example.org/INBOX";
        if (!empty($_uri['user'])) {
            $user = urldecode($_uri['user']);
            $path = strlen($path) ? urldecode($path) : 'INBOX';
        }
        // (Old?) Cyrus IMAP: imap://imap.example.org/user/test.test/Sent%40example.org
        else if (strpos($path, 'user/') === 0) {
            $path = array_map('urldecode', explode('/', $path));
            if (count($path) > 1) {
                $last = count($path) - 1;
                $user = $path[1];

                if ($last > 1 && ($pos = strrpos($path[$last], '@'))) {
                    $user .= substr($path[$last], $pos);
                    $path[$last] = substr($path[$last], 0, $pos);
                }

                $path = implode('/', array_slice($path, 2));
                if (!strlen($path)) {
                    $path = 'INBOX';
                }
            }
        }

        // E.g. for MailboxSubscribe the uri can be also just imap://imap.example.org/Folder

        if (empty($user) && empty($path)) {
            // invalid uri?
        }

        if (!empty($args)) {
            foreach (explode(';', $args) as $arg) {
                list($key, $val) = explode('=', $arg);
                $params[urldecode($key)] = urldecode($val);
            }
        }

        return array(
            'user'   => $user,
            'folder' => $path,
            'params' => $params,
        );
    }
}