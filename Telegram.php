<?php

if (file_exists('TelegramErrorLogger.php')) {
    require_once 'TelegramErrorLogger.php';
}

/**
 * Telegram Bot Class.
 *
 * @author Gabriele Grillo <gabry.grillo@alice.it>
 */
class Telegram
{
    const INLINE_QUERY = 'inline_query';
    const CALLBACK_QUERY = 'callback_query';
    const EDITED_MESSAGE = 'edited_message';
    const CHANNEL_POST = 'channel_post';
    const EDITED_CHANNEL_POST = 'edited_channel_post';
    const MESSAGE_REACTION = 'message_reaction';
    const MESSAGE_REACTION_COUNT = 'message_reaction_count';
    const MY_CHAT_MEMBER = 'my_chat_member';
    const CHAT_MEMBER = 'chat_member';
    const CHAT_JOIN_REQUEST = 'chat_join_request';
    const CHAT_BOOST = 'chat_boost';
    const REMOVED_CHAT_BOOST = 'removed_chat_boost';
    const POLL_ANSWER = 'poll_answer';
    const CHOSEN_INLINE_RESULT = 'chosen_inline_result';
    const SHIPPING_QUERY = 'shipping_query';
    const PRE_CHECKOUT_QUERY = 'pre_checkout_query';

    const REPLY = 'reply';
    const REPLY_TO_MESSAGE = 'reply_to_message';
    const MESSAGE = 'message';
    const PHOTO = 'photo';
    const VIDEO = 'video';
    const AUDIO = 'audio';
    const VOICE = 'voice';
    const ANIMATION = 'animation';
    const STICKER = 'sticker';
    const DOCUMENT = 'document';
    const LOCATION = 'location';
    const CONTACT = 'contact';

    protected ?string $bot_token        =       null;
    private ?string $webhook            =       null;
    private ?bool $log_errors           =       false;
    private ?array $data                =       [];
    private ?array $updates             =       [];
    private ?array $proxy               =       [];

    /**
     * ### Create a Telegram instance from the bot token
     * @param string $token The bot token
     * @param string $webhook The webhook url
     * @param bool $log_errors enable or disable the logging
     */
    public function __construct(
        ?string $token = null,
        ?string $webhook = null,
        bool $logs = false,
    ) {
        // Check if the PHP version is 8.0 or higher.
        if (\version_compare(phpversion(), '8.0', '<')) {
            throw new \Exception('Telegram API requires PHP version 8.0 or higher');
        }

        // Enable / Disable logger
        $this->log_errors = $logs;

        if (isset($token)) {
            $this->bot_token = $token;
        }

        if (isset($webhook)) {
            $this->webhook = $webhook;
        }

        // Check if there is a new webhook request.
        $this->getData();
    }

    /**
     * ### Contacts the various API's endpoints
     * 
     * @param ?string $api The API endpoint
     * @param array $content The request parameters as array
     * @param boolean $post Boolean tells if $content needs to be sends
     * @return array|null The JSON Telegram's reply -> **Array**.
     * 
     * @throws \Exception if the bot_token is not set.
     */
    public function endpoint(
        ?string $api,
        array $content,
        bool $post = true
    ): ?array {
        if (is_null($this->bot_token)) {
            throw new \Exception('Bot Token is required', 1);
        }

        $response = $this->sendAPIRequest(
            "https://api.telegram.org/bot{$this->bot_token}/{$api}",
            $content,
            $post
        );

        $decoded = \json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // If json_decode failed, return the raw response instead of null
            return $response;
        }

        return $decoded;
    }

    /** ### Use this method to receive incoming updates using long polling.
     * 
     * @param $offset Integer Identifier of the first update to be returned. 
     *                Must be greater by one than the highest among the identifiers of previously received updates. 
     *                By default, updates starting with the earliest unconfirmed update are returned. 
     *                An update is considered confirmed as soon as getUpdates is called with an offset higher than its update_id.
     * @param $limit Integer Limits the number of updates to be retrieved. Values between 1—100 are accepted. Defaults to 100
     * @param $timeout Integer Timeout in seconds for long polling. Defaults to 0, i.e. usual short polling
     * @param $update Boolean If true updates the pending message list to the last update received. Default to true.
     * 
     * @return array|null the updates as Array.
     */
    public function getUpdates(
        int $offset = 0,
        int $limit = 100,
        int $timeout = 0,
        bool $update = true
    ): array {
        $content = ['offset' => $offset, 'limit' => $limit, 'timeout' => $timeout];
        $this->updates = $this->endpoint(__FUNCTION__, $content);

        if ($update && ($lastElement = end($this->updates['result'] ?? [])) !== false) {
            $content['offset'] = $lastElement['update_id'] + 1;
            $content['limit'] = 1;
            $this->endpoint(__FUNCTION__, $content);
        }

        return $this->updates;
    }

    /** 
     * ## Use this method to use the bultin function like Text() or Username() on a specific update.
     * 
     * @param $update Integer The index of the update in the updates array.
     * 
     * @return void
     */
    public function serveUpdate($update): void
    {
        $this->data = $this->updates['result'][$update];
    }

    /**
     * ### Use this method to specify a URL and receive incoming updates via an outgoing webhook.
     * _Whenever there is an update for the bot, we will send an HTTPS POST request to the specified URL, containing a JSON-serialized Update. 
     * In case of an unsuccessful request, we will give up after a reasonable amount of attempts. Returns True on success._
     *
     * If you'd like to make sure that the webhook was set by you, you can specify secret data in the parameter `secret_token`.
     * If specified, the request will contain a header “`X-Telegram-Bot-Api-Secret-Token`” with the secret token as content.
     * 
     * @see https://core.telegram.org/bots/api#setwebhook
     * 
     * @param array $content The request parameters as array:
     *      - `url` (string, required): HTTPS URL to send updates to.
     *      - `certificate` (string, optional): Upload your public key certificate so that the root certificate in use can be checked. See our [self-signed](https://core.telegram.org/bots/self-signed) guide for details.
     *      - `ip_address` (string, optional): The fixed IP address which will be used to send webhook requests instead of the IP address resolved through DNS.
     *      - `max_connections` (integer, optional): Maximum allowed number of simultaneous HTTPS connections to the webhook for update delivery, 1-100. Defaults to 40. Use lower values to limit the load on your bot's server, and higher values to increase your bot's throughput.
     *      - `allowed_updates` (array of string, optional): A JSON-serialized list of the update types you want your bot to receive. For example, specify ["message", "edited_channel_post", "callback_query"] to only receive updates of these types. See Update for a complete list of available update types. Specify an empty list to receive all update types except chat_member, message_reaction, and message_reaction_count (default). If not specified, the previous setting will be used. Please note that this parameter doesn't affect updates created before the call to the setWebhook, so unwanted updates may be received for a short period of time.
     *      - `drop_pending_updates` (boolean, optional): Pass True to drop all pending updates.
     *      - `secret_token` (string, optional): A secret token to be sent in a header “`X-Telegram-Bot-Api-Secret-Token`” in every webhook request, 1-256 characters. Only characters A-Z, a-z, 0-9, _ and - are allowed. The header is useful to ensure that the request comes from a webhook set by you.
     * 
     * @return array|null The JSON Telegram's reply -> **Array**.
     * 
     * ```json
     * {"ok": true, "result": true, "description": "Webhook was set"}
     * ```
     */
    public function setWebhook(array $content): ?array
    {
        if (!isset($content['url'])) {
            $content['url'] = $this->webhook;
        } else {
            $this->webhook = $content['url'];
        }

        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to remove webhook integration if you decide to switch back to getUpdates.
     * 
     * @see https://core.telegram.org/bots/api#deletewebhook
     * 
     * @return array|null The JSON Telegram's reply -> **Array**.
     *
     * ```json
     * {"ok": true, "result": true, "description": "Webhook was deleted"}
     * ```
     */
    public function deleteWebhook(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getWebhookInfo()
    {
        return $this->endpoint(__FUNCTION__, [], false);
    }

    /**
     * ### Retrieves the JSON payload from a POST request or a getUpdates() webhook event.
     * If the incoming request contains valid JSON, it updates the instance's data property.
     * Otherwise, the current value of the data property is returned.
     * 
     * @see https://core.telegram.org/bots/api#update
     * 
     * @return array|null The decoded JSON payload if present, or the current data; null if neither is available.
     */
    public function getData(): ?array
    {
        $input = \file_get_contents('php://input');
        $decodedInput = \json_decode($input, true);

        if (!is_null($decodedInput)) {
            $this->data = $decodedInput;
        }

        return $this->data;
    }

    public function setData(array $data)
    {
        $this->data = $data;
    }

    /**
     * ### A simple method for testing your bot's authentication token.
     * Requires no parameters. Returns basic information about the bot in form of a User object.
     * 
     * @see https://core.telegram.org/bots/api#getme
     * 
     * @return array
     */
    public function getMe(): ?array
    {
        return $this->endpoint(__FUNCTION__, [], false);
    }

    /**
     * ### Logs out the current user, deleting all active sessions
     * 
     * @see https://core.telegram.org/bots/api#logout
     *
     * @return mixed Returns `true` on success.
     */
    public function logOut()
    {
        return $this->endpoint(__FUNCTION__, [], false);
    }

    /**
     * ### Close the connection to Telegram.
     * 
     * @see https://core.telegram.org/bots/api#close
     *
     * @return mixed Returns `true` on success.
     */
    public function close()
    {
        return $this->endpoint(__FUNCTION__, [], false);
    }

    /**
     * ### Use this method to send text messages. On success, the sent Message is returned.
     *
     * @see https://core.telegram.org/bots/api#sendmessage
     *
     * @param array $content An associative array containing the following keys:
     *   - `chat_id` (integer or string, required): Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     *   - `message_thread_id` (integer, optional): Unique identifier for the target message thread (topic) of the forum; for forum supergroups only
     *   - `text` (string, required): Text of the message to be sent, 1-4096 characters after entities parsing
     *   - `parse_mode` (string, optional): Mode for parsing entities in the message text. See formatting options for more details.
     *   - `entities` (array of MessageEntity, optional): A JSON-serialized list of special entities that appear in message text, which can be specified instead of parse_mode
     *   - `link_preview_options` (LinkPreviewOptions, optional): Link preview generation options for the message
     *   - `disable_notification` (boolean, optional): Sends the message silently. Users will receive a notification with no sound.
     *   - `protect_content` (boolean, optional): Protects the contents of the sent message from forwarding and saving
     *   - `reply_to_message_id` (integer, optional): If set, replies to the specified message. Defaults to the most recent received message in the chat
     *   - `reply_markup` (InlineKeyboardMarkup or ReplyKeyboardMarkup or ReplyKeyboardRemove or ForceReply, optional): Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove reply keyboard or to force a reply from the user.
     *
     * @return array An associative array containing information about the sent message on success.
     * 
     * ```json
     * {"ok":true,"result":{"message_id":123456789,"from":{"id":12345678,"is_bot":true,"first_name":"MyBot","username":"mybot"},"chat":{"id":123456789,"first_name":"John","last_name":"Doe","username":"john_doe","type":"private"},"date":1645234567,"text":"Hello, John! This is mybot."}}
     * ```
     */
    public function sendMessage(array $content): ?array
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Forward a message to a chat.
     *
     * @param array $content An associative array containing the following keys:
     *   - `chat_id` (integer or string, required): Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     *   - `message_thread_id` (Integer, optional): Unique identifier for the target message thread (topic) of the forum; for forum supergroups only 
     *   - `from_chat_id` (Integer or string, required): Unique identifier for the chat where the original message was sent (or channel username in the format @channelusername)
     *   - `disable_notification` (Boolean, optional): Sends the message silently. Users will receive a notification with no sound.
     *   - `protect_content` (Boolean, optional): Protects the contents of the sent message from forwarding and saving.
     *   - `message_id` (integer, required): Message identifier in the chat specified in from_chat_id
     * 
     * @see https://core.telegram.org/bots/api#forwardmessage
     *
     * @return array An associative array containing information about the sent message on success.
     * 
     * ```json
     * {"ok":true,"result":{"message_id":123456789,"from":{"id":12345678,"is_bot":true,"first_name":"MyBot","username":"mybot"},"chat":{"id":123456789,"first_name":"John","last_name":"Doe","username":"john_doe","type":"private"},"date":1645234567,"text":"Hello, John! This is mybot."}}
     * ```
     */
    public function forwardMessage(array $content): ?array
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Forward messages to a chat.
     *
     * @param array $content An associative array containing the following keys:
     *   - `chat_id` (integer or string, required): Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     *   - `message_thread_id` (Integer, optional): Unique identifier for the target message thread (topic) of the forum; for forum supergroups only
     *   - `from_chat_id` (Integer or string, required): Unique identifier for the chat where the original message was sent (or channel username in the format @channelusername)
     *   - `message_ids` (array of integer, required): A JSON-serialized array of message identifiers of the messages to be forwarded
     *   - `disable_notification` (boolean, optional): Sends the message silently. Users will receive a notification with no sound.
     *   - `protect_content` (boolean, optional): Protects the contents of the sent message from forwarding and saving
     *
     * @see https://core.telegram.org/bots/api#forwardmessages
     * 
     * @return array An associative array containing information about the sent message on success.
     * ```json
     * {"ok":true,"result":[{"message_id":123456789,"from":{"id":12345678,"is_bot":true,"first_name":"MyBot","username":"mybot"},"chat":{"id":123456789,"first_name":"John","last_name":"Doe","username":"john_doe","type":"private"},"date":1645234567,"text":"Hello, John! This is mybot."}]}
     * ```
     */
    public function forwardMessages(array $content): ?array
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Copy a message using the specified content array.
     *
     * @param array $content The content array to use for copying the message.
     *                       This array must contain the following elements:
     *                       - `chat_id`: Unique identifier for the target chat or username of the target channel.
     *                       - `message_thread_id`: Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     *                       - `from_chat_id`: Unique identifier for the chat where the original message was sent.
     *                       - `message_id`: Unique identifier of the message to be copied.
     *                       - `caption`: New caption for the message, 0-1024 characters after entities parsing.
     *                       - `parse_mode`: Mode for parsing entities in the message text. See formatting options for more details.
     *                       - `caption_entities`: A JSON-serialized list of special entities that appear in the new caption, which can be specified instead of parse_mode.
     *                       - `disable_notification`: Sends the message silently. Users will receive a notification with no sound.
     *                       - `protect_content`: Protects the contents of the sent message from forwarding and saving.
     *                       - `reply_markup`: Additional interface options. A JSON-serialized object for an inline keyboard, custom reply keyboard, instructions to remove reply keyboard or to force a reply from the user.
     * 
     * @see https://core.telegram.org/bots/api#copymessage
     * 
     * @return mixed Returns the MessageId of the sent message on success.
     */
    public function copyMessage(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Copy a messages using the specified content array.
     *
     * @param array $content The content array to use for copying the message.
     *                       This array must contain the following elements:
     *                       - `chat_id`: Unique identifier for the target chat or username of the target channel.
     *                       - `message_thread_id`: Unique identifier for the target message thread (topic) of the forum; for forum supergroups only.
     *                       - `from_chat_id`: Unique identifier for the chat where the original message was sent.
     *                       - `message_ids`: A JSON-serialized list of 1-100 identifiers of messages in the chat from_chat_id to copy. The identifiers must be specified in a strictly increasing order.
     *                       - `disable_notification`: Sends the message silently. Users will receive a notification with no sound.
     *                       - `protect_content`: Protects the contents of the sent message from forwarding and saving.
     *                       - `remove_caption`: Pass True to copy the messages without their captions
     * 
     * @see https://core.telegram.org/bots/api#copymessages
     * 
     * @return mixed Returns the MessageId of the sent message on success.
     */
    public function copyMessages(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendPhoto(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendAudio(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendDocument(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendVideo(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendAnimation(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendVoice(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendVideoNote(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendMediaGroup(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendLocation(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendVenue(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendContact(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendPoll(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendDice(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendChatAction(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to change the chosen reactions on a message. 
     *    Service messages can't be reacted to. Automatically forwarded messages from a channel to its discussion group have the same available reactions as messages in the channel. Returns True on success.
     * 
     * @param array $content
     *  - `chat_id`: Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     *  - `message_id`: Identifier of the target message. If the message belongs to a media group, the reaction is set to the first non-deleted message in the group instead.
     *  - `reaction`: ReactionType (`reactionTypeEmoji`, `reactionTypeCustomEmoji`) A JSON-serialized list of reaction types to set on the message. Currently, as non-premium users, bots can set up to one reaction per message. A custom emoji reaction can be used if it is either already present on the message or explicitly allowed by chat administrators.
     *  - `is_big`: Pass True to set the reaction with a big animation
     * 
     * ![Alt text](https://i.ibb.co/fX2Krcz/image.png "Emoji") | ![Alt text](https://i.ibb.co/KVvhMzf/emoji.png "Custom Emoji")
     * ```php
     * <?php
     * $this->setMessageReaction([
     *     'chat_id' => '123456',
     *     'message_id' => '123456',
     *     'reaction' => [
     *         $this->reactionTypeEmoji('👍'),                         // or $this->reactionTypeEmoji(['👍', '👎'])
     *         $this->reactionTypeCustomEmoji('5445284980978621387')   // or $this->reactionTypeCustomEmoji(['5445284980978621387', 'other custom emoji ID'])
     *     ],
     *     'is_big' => false
     * ]);
     * ```
     * 
     * @see https://core.telegram.org/bots/api#setmessagereaction
     * 
     * @throws \InvalidArgumentException
     * 
     * @return array|null
     */
    public function setMessageReaction(array $content): ?array
    {
        if (!isset($content['reaction']) || !is_array($content['reaction'])) {
            throw new \InvalidArgumentException('The reaction must be an array');
        }

        //Use array_reduce to flatten the array of reactions.
        $content['reaction'] = \json_encode(array_reduce($content['reaction'], function ($carry, $item) {
            return array_merge($carry, (array)$item);
        }, []), JSON_UNESCAPED_UNICODE);

        return $this->endpoint('setMessageReaction', $content);
    }

    /**
     * ## The reaction is based on an emoji. Returns True on success.
     *
     * @param array|string $content array or unique reaction
     * - *Reaction emoji. Currently, it can be one of* ["👍", "👎", "❤", "🔥", "🥰", "👏", "😁", "🤔", "🤯", "😱", "🤬", "😢", "🎉", "🤩", "🤮", "💩", "🙏", "👌", "🕊", "🤡", "🥱", "🥴", "😍", "🐳", "❤‍🔥", "🌚", "🌭", "💯", "🤣", "⚡", "🍌", "🏆", "💔", "🤨", "😐", "🍓", "🍾", "💋", "🖕", "😈", "😴", "😭", "🤓", "👻", "👨‍💻", "👀", "🎃", "🙈", "😇", "😨", "🤝", "✍", "🤗", "🫡", "🎅", "🎄", "☃", "💅", "🤪", "🗿", "🆒", "💘", "🙉", "🦄", "😘", "💊", "🙊", "😎", "👾", "🤷‍♂", "🤷", "🤷‍♀", "😡"]
     *  
     * ```php
     * <?php
     *  $this->reactionTypeEmoji(['👍', '👎');
     *  // or
     *  $this->reactionTypeEmoji('🔥');
     * ```
     * 
     * @see https://core.telegram.org/bots/api#reactiontypeemoji
     *
     * @return array|null
     */
    public function reactionTypeEmoji($content): array
    {
        if (!is_array($content)) {
            $content = [$content]; // Convert the content into an array if it is not already an array
        }

        // Sempre retorna uma matriz de matrizes para cada emoji
        return array_map(fn ($emoji) => ['type' => 'emoji', 'emoji' => $emoji], $content);
    }

    /**
     * ## The reaction is based on a custom emoji.
     * 
     * ```php
     * <?php
     *  $this->reactionTypeCustomEmoji(['5445284980978621387', 'Other custom emoji ID');
     *  // or
     *  $this->reactionTypeCustomEmoji('5445284980978621387');
     * ```
     * 
     * @see https://core.telegram.org/bots/api#reactiontypecustomemoji
     *
     * @param array|string $content array or unique reaction
     * @return array|null
     */
    public function reactionTypeCustomEmoji($content): ?array
    {
        if (!is_array($content)) {
            $content = [$content]; // Convert the content into an array if it is not already an array
        }

        // Always returns an array of arrays for each emoji.
        return array_map(fn ($emoji) => ['type' => 'custom_emoji', 'custom_emoji_id' => $emoji], $content);
    }

    public function getUserProfilePhotos(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to get basic info about a file and prepare it for downloading. 
     *      For the moment, bots can download files of up to 20MB in size. On success, a File object is returned. 
     *      The file can then be downloaded via the link https://api.telegram.org/file/bot{token}/{file_path}, where `file_path` is taken from the response. 
     *      It is guaranteed that the link will be valid for at least 1 hour. 
     *      When the link expires, a new one can be requested by calling getFile again.
     * @param string $file_id String File identifier to get info about
     * 
     * @see https://core.telegram.org/bots/api#getfile
     * 
     * @return array|null the JSON Telegram's reply.
     */
    public function getFile(string $file_id)
    {
        return $this->endpoint(__FUNCTION__, [
            'file_id' => $file_id
        ]);
    }

    public function banChatMember(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function unbanChatMember(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function restrictChatMember(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function promoteChatMember(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatAdministratorCustomTitle(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function banChatSenderChat(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function unbanChatSenderChat(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatPermissions(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function exportChatInviteLink(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function createChatInviteLink(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editChatInviteLink(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function revokeChatInviteLink(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function approveChatJoinRequest(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function declineChatJoinRequest(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatPhoto(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteChatPhoto(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatTitle(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatDescription(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to pin a message in a supergroup. 
     * The bot must be an administrator in the chat for this to work and must have the appropriate admin rights.  
     *
     * @param array $content
     * - `chat_id` (string|int, required) Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     * - `message_id` (int, required) Identifier of a message to pin
     * - `disable_notification` (bool, optional) Pass True, if it is not necessary to send a notification to all group members about the new pinned message. Notifications are always disabled in channels. Defaults to False.
     *
     * @see https://core.telegram.org/bots/api#pinchatmessage
     *
     * @return array|null
     */
    public function pinChatMessage(array $content): ?array
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to remove a message from the list of pinned messages in a chat. 
     * If the chat is not a private chat, the bot must be an administrator in the chat for this to work and must have the 'can_pin_messages' administrator right in a supergroup or 'can_edit_messages' administrator right in a channel. 
     * 
     * @param array $content
     * - `chat_id` (string|int, required) Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     * - `message_id` (int, optional) Identifier of a message to unpin. If not specified, the most recent pinned message (by sending date) will be unpinned.
     * 
     * @see https://core.telegram.org/bots/api#unpinchatmessage
     * 
     * @return array|null
     */
    public function unpinChatMessage(array $content): ?array
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function unpinAllChatMessages(array $content): ?array
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function leaveChat(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getChat(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getChatAdministrators(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getChatMembersCount(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getChatMember(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatStickerSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteChatStickerSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getForumTopicIconStickers(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function createForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function closeForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function reopenForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function unpinAllForumTopicMessages(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editGeneralForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function closeGeneralForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function reopenGeneralForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function hideGeneralForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function unhideGeneralForumTopic(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function unpinAllGeneralForumTopicMessages(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to answer a callback query sent to the bot by a user.
     *
     * @param array $content Content of the callback query to be answered
     * @return void
     */
    public function answerCallbackQuery(array $content)
    {
        // Convert show_alert value to string
        if (array_key_exists('show_alert', $content) && is_bool($content['show_alert'])) {
            $content['show_alert'] = $content['show_alert'] ? 'true' : 'false';
        }

        $this->endpoint(__FUNCTION__, $content);
    }

    public function getUserChatBoosts(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setMyCommands(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteMyCommands(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getMyCommands(array $content = [])
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setMyName(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getMyName(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setMyDescription(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getMyDescription(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setMyShortDescription(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getMyShortDescription(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setChatMenuButton(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getChatMenuButton(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setMyDefaultAdministratorRights(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getMyDefaultAdministratorRights(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editMessageText(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editMessageCaption(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editMessageMedia(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editMessageLiveLocation(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function stopMessageLiveLocation(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function editMessageReplyMarkup(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function stopPoll(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to delete a message, including service messages, with the following limitations:
     * - **A message can only be deleted if it was sent less than 48 hours ago.**
     * - **Service messages about a supergroup, channel, or forum topic creation can't be deleted.**
     * - **A dice message in a private chat can only be deleted if it was sent more than 24 hours ago.**
     * - **Bots can delete outgoing messages in private chats, groups, and supergroups.**
     * - **Bots can delete incoming messages in private chats.**
     * - **Bots granted can_post_messages permissions can delete outgoing messages in channels.**
     * - **If the bot is an administrator of a group, it can delete any message there.**
     * - **If the bot has can_delete_messages permission in a supergroup or a channel, it can delete any message there.**
     *
     * @param array $content
     *  - `chat_id` (string|int, required) Unique identifier for the target chat or username of the target channel (in the format @channelusername)
     *  - `message_id` (int, required) Identifier of the message to delete
     *  - `inline_message_id` (string, optional) Identifier of the inline message
     *
     * @see https://core.telegram.org/bots/api#deletemessage
     * 
     * @return void
     */
    public function deleteMessage(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteMessages(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendSticker(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getStickerSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getCustomEmojiStickers(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function uploadStickerFile(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function createNewStickerSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function addStickerToSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setStickerPositionInSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteStickerFromSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setStickerEmojiList(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setStickerKeywords(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setStickerMaskPosition(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setStickerSetTitle(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setStickerSetThumbnail(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setCustomEmojiStickerSetThumbnail(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function deleteStickerSet(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function answerInlineQuery(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function answerWebAppQuery(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendInvoice(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function createInvoiceLink(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function answerShippingQuery(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function answerPreCheckoutQuery(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function sendGame(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function setGameScore(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    public function getGameHighScores(array $content)
    {
        return $this->endpoint(__FUNCTION__, $content);
    }

    /**
     * ### Use this method to to download a file from the Telegram servers.
     * @param string $file_path String File path on Telegram servers
     * @param string $save_path String File path where save the file.
     */
    public function downloadFile(string $file_path, $save_path)
    {
        $in = fopen("https://api.telegram.org/file/bot{$this->bot_token}/{$file_path}", 'rb');
        $out = fopen($save_path, 'wb');

        while ($chunk = fread($in, 8192)) {
            fwrite($out, $chunk, 8192);
        }
        fclose($in);
        fclose($out);
    }

    /**
     * ### For text messages, the actual UTF-8 text of the message.
     * 
     * @return string|null
     */
    public function Text(): ?string
    {
        $type = $this->getUpdateType();
        return match ($type) {
            self::EDITED_MESSAGE, self::CHANNEL_POST, self::EDITED_CHANNEL_POST => $this->data[$type]['text'],
            self::INLINE_QUERY, self::CHOSEN_INLINE_RESULT => $this->data[$type]['query'],
            self::CALLBACK_QUERY => $this->data[$type]['data'],
            default => $this->data[self::MESSAGE]['text'] ?? null
        };
    }

    /**
     * ### Unique identifier for a chat.
     * This number may have more than 32 significant bits and some programming languages may have difficulty/silent defects in interpreting it.
     * 
     * @return int|null
     */
    public function ChatID(): ?int
    {
        $type = $this->getUpdateType();
        return match ($type) {
            self::EDITED_MESSAGE,
            self::CHANNEL_POST,
            self::EDITED_CHANNEL_POST,
            self::MESSAGE_REACTION,
            self::MESSAGE_REACTION_COUNT,
            self::MY_CHAT_MEMBER,
            self::CHAT_MEMBER,
            self::CHAT_JOIN_REQUEST,
            self::CHAT_BOOST,
            self::REMOVED_CHAT_BOOST => $this->data[$type]['chat']['id'],
            self::CALLBACK_QUERY => $this->data[self::CALLBACK_QUERY][self::MESSAGE]['chat']['id'],
            self::POLL_ANSWER => isset($this->data[self::POLL_ANSWER]['voter_chat']) ? $this->data[self::POLL_ANSWER]['voter_chat']['id'] : null,
            default => $this->data[self::MESSAGE]['chat']['id'] ?? null,
        };
    }

    /**
     * ### Unique identifier for a user or bot.
     * This number may have more than 32 significant bits and some programming languages may have difficulty/silent defects in interpreting it.
     * 
     * @return int|null
     */
    public function UserID(): ?int
    {
        $type = $this->getUpdateType();
        return match ($type) {
            self::EDITED_MESSAGE,
            self::CHANNEL_POST,
            self::EDITED_CHANNEL_POST,
            self::INLINE_QUERY,
            self::CHOSEN_INLINE_RESULT,
            self::CALLBACK_QUERY,
            self::SHIPPING_QUERY,
            self::PRE_CHECKOUT_QUERY,
            self::MY_CHAT_MEMBER,
            self::CHAT_MEMBER,
            self::CHAT_JOIN_REQUEST => $this->data[$type]['from']['id'],
            self::CHAT_BOOST,
            self::REMOVED_CHAT_BOOST => $this->data[$type]['boost']['source']['user']['id'],
            self::MESSAGE_REACTION => $this->data[self::MESSAGE_REACTION]['user']['id'] ?? null,
            self::POLL_ANSWER => $this->data[self::POLL_ANSWER]['user']['id'] ?? null,
            default => $this->data[self::MESSAGE]['from']['id'] ?? null,
        };
    }

    /**
     * Unique message identifier inside a chat.
     * 
     * @return int|null
     */
    public function MessageID(): ?int
    {
        $type = $this->getUpdateType();
        return match ($type) {
            self::EDITED_MESSAGE => $this->data[self::EDITED_MESSAGE]['message_id'],
            self::CHANNEL_POST => $this->data[self::CHANNEL_POST]['message_id'],
            self::EDITED_CHANNEL_POST => $this->data[self::EDITED_CHANNEL_POST]['message_id'],
            self::MESSAGE_REACTION => $this->data[self::MESSAGE_REACTION]['message_id'],
            self::MESSAGE_REACTION_COUNT => $this->data[self::MESSAGE_REACTION_COUNT]['message_id'],
            self::CALLBACK_QUERY => $this->data[self::CALLBACK_QUERY][self::MESSAGE]['message_id'],
            default => $this->data[self::MESSAGE]['message_id'] ?? null,
        };
    }

    public function InlineMessageID()
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['inline_message_id'] ?? null;
    }

    /**
     * User's or bot's first name
     * 
     * @return string|null
     */
    public function FirstName(): ?string
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['from']['first_name'] ?? null;
    }

    /**
     * User's or bot's last name
     * 
     * @return string|null
     */
    public function LastName(): ?string
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['from']['last_name'] ?? null;
    }

    /**
     * User's or bot's full name
     * 
     * @return string|null
     */
    public function FullName(): ?string
    {
        return trim($this->FirstName() . " " . $this->LastName()) ?? null;
    }

    /**
     * User's or bot's username
     * 
     * @return string|null
     */
    public function Username(): ?string
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['from']['username'] ?? null;
    }

    /**
     * True, if this user is a Telegram Premium user.
     * 
     * @return bool
     */
    public function isPremium(): bool
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['from']['is_premium'] ?? false;
    }

    /**
     * Check if the message is from a bot
     *
     * @return bool Whether the message is from a bot
     */
    public function isBot(): bool
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['from']['is_bot'] ?? false;
    }

    /**
     * [IETF language tag](https://en.wikipedia.org/wiki/IETF_language_tag) of the user's language
     * 
     * @return string
     */
    public function Language(): string
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['from']['language_code'] ?? 'en';
    }

    public function Caption()
    {
        $type = $this->getUpdateType();
        return $this->data[$type]['caption'];
    }

    /**
     * @return array|null the String reply_to_message message_id.
     */
    public function ReplyToMessageID()
    {
        return $this->data[self::MESSAGE][self::REPLY_TO_MESSAGE]['message_id'];
    }

    /**
     * @return array|null the String reply_to_message forward_from user_id.
     */
    public function ReplyToMessageFromUserID()
    {
        return $this->data[self::MESSAGE][self::REPLY_TO_MESSAGE]['forward_from']['id'];
    }

    /**
     * @return array|null the Array inline_query.
     */
    public function Inline_Query()
    {
        return $this->data[self::INLINE_QUERY];
    }

    /**
     * @return array|null the String callback_query.
     */
    public function Callback_Query()
    {
        return $this->data[self::CALLBACK_QUERY] ?? null;
    }

    /**
     * Get the Callback_ID of the current update
     * 
     * @return int|null
     */
    public function Callback_ID(): ?int
    {
        return $this->data[self::CALLBACK_QUERY]['id'] ?? null;
    }

    /**
     *  ~Data associated with the callback button. 
     * Be aware that the message originated the query can contain no callback buttons with this data.~
     * 
     * @deprecated Use `$bot->Text()` instead
     * 
     * @return string|null the String callback_data.
     */
    public function Callback_Data(): ?string
    {
        return $this->data[self::CALLBACK_QUERY]['data'] ?? null;
    }

    /**
     * @return array|null the Message.
     */
    public function Callback_Message()
    {
        return $this->data[self::CALLBACK_QUERY][self::MESSAGE];
    }

    /**
     * @deprecated Use ChatId() instead
     * 
     * @return array|null the String callback_query.
     */
    public function Callback_ChatID()
    {
        return $this->data[self::CALLBACK_QUERY][self::MESSAGE]['chat']['id'];
    }

    /**
     * @return int|null the String callback_query from_id.
     */
    public function Callback_FromID(): ?int
    {
        return $this->data[self::CALLBACK_QUERY]['from']['id'];
    }

    /**
     * ### Global identifier, uniquely corresponding to the chat to which the message with the callback button was sent. 
     * Useful for high scores in games. 
     *
     * @return string|null
     */
    public function Callback_Instance(): ?string
    {
        return $this->data[self::CALLBACK_QUERY]['chat_instance'];
    }

    /**
     * @return array|null the String message's date.
     */
    public function Date()
    {
        return $this->data[self::MESSAGE]['date'];
    }


    public function Location()
    {
        return $this->data['message']['location'];
    }


    public function UpdateID()
    {
        return $this->data['update_id'];
    }


    public function UpdateCount()
    {
        return count($this->updates['result']);
    }


    public function FromID()
    {
        return $this->data['message']['forward_from']['id'];
    }


    public function FromChatID()
    {
        return $this->data['message']['forward_from_chat']['id'];
    }

    /**
     *  @return array|null BOOLEAN true if the message is from a Group chat, false otherwise.
     */
    public function messageFromGroup()
    {
        if ($this->data['message']['chat']['type'] == 'private') {
            return false;
        }

        return true;
    }


    /**
     *  @return array|null a String of the contact phone number.
     */
    public function getContactPhoneNumber()
    {
        if ($this->getUpdateType() == self::CONTACT) {
            return $this->data['message']['contact']['phone_number'];
        }

        return '';
    }

    /**
     *  @return array|null a String of the title chat.
     */
    public function messageFromGroupTitle()
    {
        if ($this->data['message']['chat']['type'] != 'private') {
            return $this->data['message']['chat']['title'];
        }

        return '';
    }

    /**
     * ## Construct an schema for keyboard markup for Telegram messages.
     *
     * This function takes an array of button rows, where each row is itself an
     * array of KeyboardButton objects, and encodes it into a JSON string.
     * This JSON string can then be passed to the Telegram API to display an
     * keyboard with the specified buttons. For instance:
     * 
     * ```php
     * <?php
     * $this->buildKeyBoard([
     *     [$this->buildKeyboardButton('First Line - BTN 1'), $this->buildKeyboardButton('First Line - BTN 2')],
     *     [$this->buildKeyboardButton('Second Line - BTN 1')]
     * ]);
     * ```
     *
     * @param array $options An array of button rows, with each row being an array of KeyboardButton objects.
     *   - `is_persistent` (boolean) Requests clients to always show the keyboard when the regular keyboard is hidden. Defaults to false, in which case the custom keyboard can be hidden and opened with a keyboard icon.
     *   - `resize_keyboard` (boolean) Requests clients to resize the keyboard vertically for optimal fit (e.g., make the keyboard smaller if there are just two rows of buttons). Defaults to false, in which case the custom keyboard is always of the same height as the app's standard keyboard.
     *   - `one_time_keyboard` (boolean) Requests clients to hide the keyboard as soon as it's been used. The keyboard will still be available, but clients will automatically display the usual letter-keyboard in the chat - the user can press a special button in the input field to see the custom keyboard again. Defaults to false.
     *   - `input_field_placeholder` (string) The placeholder to be shown in the input field when the keyboard is active; 1-64 characters
     *   - `selective` (boolean) Use this parameter if you want to show the keyboard to specific users only. Targets: 1) users that are @mentioned in the text of the Message object; 2) if the bot's message is a reply to a message in the same chat and forum topic, sender of the original message.
     *                           Example: A user requests to change the bot's language, bot replies to the request with a keyboard to select the new language. Other users in the group don't see the keyboard.
     * @return string JSON encoded string of the keyboard.
     */
    public function buildKeyBoard(
        array $options,
        $is_persistent = false,
        $resize_keyboard = false,
        $one_time_keyboard = false,
        $input_field_placeholder = null,
        $selective = true
    ) {
        $replyMarkup = array_filter([
            'keyboard'                  => $options,
            'is_persistent'             => $is_persistent,
            'resize_keyboard'           => $resize_keyboard,
            'one_time_keyboard'         => $one_time_keyboard,
            'input_field_placeholder'   => $input_field_placeholder ?: null,
            'selective'                 => $selective,
        ]);

        return \json_encode($replyMarkup);
    }

    /**
     * ## Construct an schema for inline keyboard markup for Telegram messages.
     *
     * This function takes an array of button rows, where each row is itself an
     * array of InlineKeyboardButton objects, and encodes it into a JSON string.
     * This JSON string can then be passed to the Telegram API to display an
     * inline keyboard with the specified buttons. For instance:
     * 
     * ```php
     * <?php
     * $this->buildInlineKeyBoard([
     *     [$this->buildInlineKeyboardButton('First Line - BTN 1', null, '/command'), $this->buildInlineKeyboardButton('First Line - BTN 2', null, '/command')],
     *     [$this->buildInlineKeyboardButton('Second Line - BTN 1', null, '/command')]
     * ]);
     * ```
     *
     * @param array $options An array of button rows, with each row being an array of InlineKeyboardButton objects.
     * @return string JSON encoded string of the inline keyboard markup.
     */
    public function buildInlineKeyBoard(array $options): string
    {
        return \json_encode([
            'inline_keyboard' => $options,
        ]);
    }

    /**
     * ## Creates an inline keyboard button Item. You must use exactly one of the optional fields.
     *
     * @see https://core.telegram.org/bots/api#inlinekeyboardbutton
     *
     * @param string text (string, required): Text of the label on the button
     * @param string url (string, optional): HTTP or tg:// URL to be opened when the button is pressed. Links like tg://user?id=userid can be used to mention a user by their ID without using a username, if allowed by privacy settings.
     * @param string callback_data (string, optional): Data to be sent in a callback query to the bot when the button is pressed, 1-64 bytes
     * @param array login_url (LoginUri[], optional): An HTTPS URL used to automatically authorize the user. Can be used as a replacement for the Telegram Login Widget.
     * @param string switch_inline_query (string, optional): If set, pressing the button will prompt the user to select one of their chats, open that chat and insert the bot username and the specified inline query in the input field. It may be empty, in which case only the bot's username will be inserted.
     * @param string switch_inline_query_current_chat (string, optional): If set, pressing the button will insert the bot's username and the specified inline query in the input field of the current chat. It may be empty, in which case only the bot's username will be inserted.
     * @param array switch_inline_query_chosen_chat (SwitchInlineQueryChosenChat[], optional): If set, pressing the button will prompt the user to select one of their chats of the specified type, open that chat and insert the bot username and the specified inline query in the input field.
     * @param array callback_game (CallbackGame[], optional): Description of the game that will be launched when the user presses the button.
     * @param bool pay (boolean, optional): Specify True to send a Pay button.
     *
     * @return array InlineKeyboardButton
     */
    public function buildInlineKeyboardButton(
        string $text,
        ?string $url = '',
        ?string $callback_data = '',
        ?array $login_url = [],
        ?string $switch_inline_query = null,
        ?string $switch_inline_query_current_chat = null,
        ?array $switch_inline_query_chosen_chat = [],
        ?array $callback_game = [],
        ?bool $pay = false
    ): array {
        $replyMarkup = array_filter([
            'text'                                    => $text,
            'url'                                     => $url ?: null,
            'callback_data'                           => $callback_data ?: null,
            'login_url'                               => $login_url ?: null,
            'switch_inline_query'                     => $switch_inline_query,
            'switch_inline_query_current_chat'        => $switch_inline_query_current_chat,
            'switch_inline_query_chosen_chat'         => $switch_inline_query_chosen_chat,
            'callback_game'                           => $callback_game ?: null,
            'pay'                                     => $pay ? 'true' : null,
        ]);

        return $replyMarkup;
    }

    /**
     * ## Creates an keyboard button Item. You must use exactly one of the optional fields.
     *
     * @see https://core.telegram.org/bots/api#keyboardbutton
     *
     * @param string $text (string, required): Text of the button. If none of the optional fields are used, it will be sent as a message when the button is pressed
     * @param array $request_users (array, optional): If specified, pressing the button will open a list of suitable users. Identifiers of selected users will be sent to the bot in a “users_shared” service message. Available in private chats only.
     * @param array $request_chat (array, optional): If specified, pressing the button will open a list of suitable chats. Tapping on a chat will send its identifier to the bot in a “chat_shared” service message. Available in private chats only.
     * @param bool $request_contact (bool, optional): If True, the user's phone number will be sent as a contact when the button is pressed. Available in private chats only.
     * @param bool $request_location (bool, optional): If True, the user's current location will be sent when the button is pressed. Available in private chats only.
     * @param array $request_poll (array, optional): If specified, the user will be asked to create a poll and send it to the bot when the button is pressed. Available in private chats only.
     *
     * @return array KeyboardButton
     */
    public function buildKeyboardButton(
        string $text,
        ?array $request_users = [],
        ?array $request_chat = [],
        ?bool $request_contact = false,
        ?bool $request_location = false,
        ?array $request_poll = [],
    ): ?array {
        $replyMarkup = array_filter([
            'text'                 => $text,
            'request_users'        => $request_users ?: null,
            'request_chat'         => $request_chat ?: null,
            'request_contact'      => $request_contact,
            'request_location'     => $request_location,
            'request_poll'         => $request_poll ?: null,
        ]);

        return $replyMarkup;
    }

    /**
     * ## Remove the keyboard (buildKeyboardButton) from a message.
     * Upon receiving a message with this object, Telegram clients will remove the current custom keyboard and display the default letter-keyboard. By default, custom keyboards are displayed until a new keyboard is sent by a bot. An exception is made for one-time keyboards that are hidden immediately after the user presses a button
     *
     * @see https://core.telegram.org/bots/api#replykeyboardremove
     * 
     * @param boolean|null $selective Use this parameter if you want to remove the keyboard for specific users only. Targets: 1) users that are @mentioned in the text of the Message object; 2) if the bot's message is a reply to a message in the same chat and forum topic, sender of the original message.
     *                                Example: A user votes in a poll, bot returns confirmation message in reply to the vote and removes the keyboard for that user, while still showing the keyboard with poll options to users who haven't voted yet.
     * @return string ReplyKeyboardRemove
     */
    public function buildKeyBoardHide(?bool $selective = true): ?string
    {
        return \json_encode([
            'remove_keyboard' => true,
            'selective'       => $selective,
        ]);
    }

    /**
     * ## Creates an webapp keyboard button Item.
     * 
     * @see https://core.telegram.org/bots/api#webappinfo
     * 
     * @param string $text (string, required): Text of the label on the button
     * @param string $url (string, required): An HTTPS URL of a Web App to be opened with additional data as specified in Initializing Web Apps
     * 
     * @return array KeyboardButton
     */
    public function buildKeyBoardWebApp(string $text, string $url): array
    {
        return [
            'text' => $text,
            'web_app' => ['url' => $url]
        ];
    }

    /**
     * ## Upon receiving a message with this object, Telegram clients will display a reply interface to the user (act as if the user has selected the bot's message and tapped 'Reply'). 
     * This can be extremely useful if you want to create user-friendly step-by-step interfaces without having to sacrifice privacy mode.
     *
     * @param string $input_field_placeholder The placeholder to be shown in the input field when the reply is active; 1-64 characters
     * @param boolean $selective Use this parameter if you want to force reply from specific users only. Targets: 1) users that are @mentioned in the text of the Message object; 2) if the bot's message is a reply to a                           message in the same chat and forum topic, sender of the original message.
     * @return string|null
     */
    public function buildForceReply(
        ?string $input_field_placeholder = '',
        ?bool $selective = true
    ): ?string {
        return \json_encode([
            'force_reply'             =>    true,
            'input_field_placeholder' =>    $input_field_placeholder,
            'selective'               =>    $selective,
        ]);
    }


    /**
     * ## At most one of the optional parameters can be present in any given update.
     * 
     * @see https://core.telegram.org/bots/api#update
     * 
     * @throws \Exception
     * 
     * @return string Represents an incoming update.
     *  `message`, `edited_message`, `channel_post`, `edited_channel_post`, `message_reaction`, `message_reaction_count`, `inline_query`, `chosen_inline_result`, `callback_query`, `shipping_query`, `pre_checkout_query`, `poll`, `poll_answer`, `my_chat_member`, `chat_member`, `chat_join_request`, `chat_boost`, `removed_chat_boost`
     */
    public function getUpdateType(): ?string
    {
        $keys = array_keys($this->data);

        if (!isset($keys[1])) {
            throw new \Exception("Invalid update");
        }

        return $keys[1];
    }

    /**
     * @return string the HTTP 200 to Telegram.
     */
    public function respondSuccess()
    {
        http_response_code(200);
        return \json_encode(['status' => 'success'], JSON_PRETTY_PRINT);
    }

    /**
     * Sends a request to the Telegram Bot API.
     *
     * @param string $url The URL to send the request to.
     * @param array $content The data to send with the request.
     * @param bool $post Whether the request should be a POST request.
     *
     * @return string The result of the request.
     */
    private function sendAPIRequest(
        string $url,
        array $content,
        bool $post = true
    ) {
        if (!$post) {
            // Convert array to query string if request is not POST
            $url .= '?' . http_build_query($content);
        } else {
            if (isset($content['chat_id'])) {
                $url .= "?chat_id={$content['chat_id']}";
                unset($content['chat_id']);
            }

            // Check if 'certificate' is provided and needs to be sent as a file
            if (isset($content['certificate']) && is_file($content['certificate'])) {
                $certificatePath = $content['certificate'];
                $content['certificate'] = new \CURLFile($certificatePath);
            } else if (isset($content['certificate']) && is_string($content['certificate'])) {
                // Assuming the certificate is provided as a string path to the file
                $content['certificate'] = new \CURLFile($content['certificate']);
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HEADER => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => $post,
            CURLOPT_POSTFIELDS => $post ? $content : null,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        // Options define for proxies
        foreach ([
            'type' => CURLOPT_PROXYTYPE,
            'auth' => CURLOPT_PROXYUSERPWD,
            'url' => CURLOPT_PROXY,
            'port' => CURLOPT_PROXYPORT
        ] as $key => $value) {
            if (!empty($this->proxy[$key])) {
                curl_setopt($ch, $value, $this->proxy[$key]);
            }
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $result = \json_encode(['ok' => false, 'curl_error_code' => curl_errno($ch), 'curl_error' => curl_error($ch)]);
        }
        curl_close($ch);

        // Error Log setup
        if ($this->log_errors && \class_exists('TelegramErrorLogger')) {
            \call_user_func(
                ['TelegramErrorLogger', 'log'],
                \json_decode($result, true),
                $this->getData() === null ? [$content] : [$this->getData(), $content]
            );
        }

        return $result;
    }

    /**
     * Simulate user type /comand
     *
     * @param string $command
     * @return void
     */
    public function runCommand(String $command): ?array
    {
        $ch = curl_init($this->webhook);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode([
            "update_id" => $this->getData()['update_id'] + 1,
            "message" => [
                "message_id" => $this->MessageID() + 1,
                "from" => $this->getData()['message']['from'],
                "chat" => $this->getData()['message']['chat'],
                "date" => \time(),
                "text" => $command,
                "entities" => [
                    [
                        "offset" => 0,
                        "length" => 6,
                        "type" => "bot_command"
                    ]
                ]
            ]
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpcode != 200) {
            throw new \Exception("Response received with status code: $httpcode");
        }

        curl_close($ch);

        return \json_decode($response, true);
    }
}
