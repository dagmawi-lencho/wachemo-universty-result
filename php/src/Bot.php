<?php

declare(strict_types=1);

/**
 * Handles one Telegram update per webhook request.
 *
 * Dispatch order matches the original python-telegram-bot setup: the login conversation
 * first, then year/semester/back callbacks, then /policy /about /help, then media filters.
 */
final class Bot
{
    private const AUTH_USERNAME = 'auth_username';
    private const AUTH_PASSWORD = 'auth_password';
    private const END = 'end';
    private const SESSION_TTL_SECONDS = 1800;

    private const MENU_TEXT_PATTERN = '/^(Grade Report|Registered Courses|Placed Department|View Profile|Developer)$/';
    private const MENU_CALLBACK_PATTERN = '/^menu_(grade|courses|placement|profile|developer)$/';
    private const MENU_CALLBACK_ACTIONS = [
        'menu_grade' => 'grade',
        'menu_courses' => 'courses',
        'menu_placement' => 'placement',
        'menu_profile' => 'profile',
        'menu_developer' => 'developer',
    ];
    private const MENU_TEXT_ACTIONS = [
        'grade report' => 'grade',
        'registered courses' => 'courses',
        'placed department' => 'placement',
        'view profile' => 'profile',
        'developer' => 'developer',
    ];
    private const DEVELOPER_TEXT = "👨‍💻 Developer Contact\n\n"
        . "Name: Dagmawi Lencho\n"
        . "Website: https://dagmawilencho.dev\n"
        . "Telegram: @Duyyee";

    /** Media filters in the same order as the original handlers. */
    private const MEDIA_REPLIES = [
        'photo' => "📸 Dear %s, I currently don't support searching or processing photos. ",
        'video' => "🎥 Dear %s, I currently don't support searching or processing videos. ",
        'contact' => "📇 Dear %s, I currently don't support processing contacts. ",
        'poll' => "📊 Dear %s, I currently don't support processing polls. ",
        'caption' => "📝 Dear %s, I currently don't support processing captions. ",
        'sticker' => "🖼️ Dear %s, I currently don't support processing stickers. ",
        'animation' => "🎞️ Dear %s, I currently don't support processing animations. ",
        'document' => "📄 Dear %s, I currently don't support processing documents. ",
        'audio' => "🎵 Dear %s, I currently don't support processing audios. ",
        'dice' => "🎲 Dear %s, I currently don't support processing dice. ",
    ];
    private const ATTACHMENT_FIELDS = [
        'animation', 'audio', 'contact', 'dice', 'document', 'game', 'invoice', 'location', 'paid_media',
        'passport_data', 'photo', 'poll', 'sticker', 'story', 'successful_payment', 'venue', 'video',
        'video_note', 'voice',
    ];

    private Telegram $tg;
    private Store $store;

    private int $userId = 0;
    private int $chatId = 0;
    private array $from = [];
    private ?array $message = null;
    private ?array $query = null;

    private ?string $state = null;
    private array $data = [];
    private ?array $portal = null;
    private int $portalExpiresAt = 0;
    private ?PortalClient $client = null;

    public function __construct(Telegram $tg, Store $store)
    {
        $this->tg = $tg;
        $this->store = $store;
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query']['from'])) {
            $this->query = $update['callback_query'];
            $this->from = $this->query['from'];
            $this->chatId = (int) ($this->query['message']['chat']['id'] ?? $this->from['id']);
        } elseif (isset($update['message']['from'])) {
            $this->message = $update['message'];
            $this->from = $this->message['from'];
            $this->chatId = (int) $this->message['chat']['id'];
        } else {
            return;
        }
        $this->userId = (int) $this->from['id'];

        $this->store->lock($this->userId);
        try {
            $row = $this->store->load($this->userId);
            $this->state = $row['state'];
            $this->data = $row['data'];
            $this->portal = $row['portal'];
            $this->portalExpiresAt = $row['portal_expires_at'];

            $this->dispatch();
        } finally {
            $this->persist();
            $this->store->unlock($this->userId);
        }
    }

    private function dispatch(): void
    {
        if ($this->handleConversation()) {
            return;
        }

        if ($this->query !== null) {
            $data = (string) ($this->query['data'] ?? '');
            if (preg_match('/^year_\d+$/', $data)) {
                $this->handleYearSelection();
            } elseif (preg_match('/^sem_\d+$/', $data)) {
                $this->handleSemesterSelection();
            } elseif ($data === 'back_main') {
                $this->backToMain();
            } elseif ($data === 'back_years') {
                $this->backToYears();
            } else {
                $this->answerQuery();
            }
            return;
        }

        if ($this->isCommand('policy')) {
            $this->policy();
        } elseif ($this->isCommand('about')) {
            $this->about();
        } elseif ($this->isCommand('help')) {
            $this->help();
        } else {
            $this->filterMedia();
        }
    }

    /**
     * The login conversation: entry points, then state handlers, then fallbacks.
     *
     * @return bool whether the conversation handled the update.
     */
    private function handleConversation(): bool
    {
        $text = $this->messageText();
        $callbackData = $this->query !== null ? (string) ($this->query['data'] ?? '') : null;

        $handler = null;
        if ($this->isCommand('start')) {
            $handler = 'start';
        } elseif ($text !== null && preg_match(self::MENU_TEXT_PATTERN, $text)) {
            $handler = 'beginAction';
        } elseif ($callbackData !== null && preg_match(self::MENU_CALLBACK_PATTERN, $callbackData)) {
            $handler = 'beginAction';
        } elseif ($this->state === self::AUTH_USERNAME || $this->state === self::AUTH_PASSWORD) {
            if ($text !== null && !$this->isCommand()) {
                $handler = $this->state === self::AUTH_USERNAME ? 'handleAuthUsername' : 'handleAuthPassword';
            } elseif ($callbackData === 'back_main') {
                $handler = 'backToMain';
            } elseif ($this->isCommand('cancel')) {
                $handler = 'cancel';
            }
        }

        if ($handler === null) {
            return false;
        }

        $next = $this->{$handler}();
        if ($handler === 'backToMain' || $next === self::END) {
            $this->state = null;
        } elseif (is_string($next)) {
            $this->state = $next;
        }
        return true;
    }

    private function start(): string
    {
        try {
            $this->tg->sendChatAction($this->chatId);
            $firstName = (string) ($this->from['first_name'] ?? '');
            if ($firstName === '') {
                $firstName = 'Student';
            }
            $panelId = $this->data['panel_message_id'] ?? null;
            $activeClient = $this->getActiveSessionClient();
            $this->data = [];
            if ($panelId) {
                $this->data['panel_message_id'] = $panelId;
            }

            if ($activeClient !== null) {
                $this->renderPanel("👋 Welcome back, {$firstName}!\n\nSession is active.", self::mainMenuMarkup());
                return self::END;
            }

            $this->data['pending_action'] = 'menu_home';
            $this->renderPanel(
                "👋 Welcome, {$firstName}, to WCU RESULT!\n\n"
                . "For privacy, login is temporary and expires after 30 minutes.\n\n"
                . 'Enter your portal username (e.g., WCU174538):'
            );
            return self::AUTH_USERNAME;
        } catch (Throwable $e) {
            error_log('[wcu-bot] start failed: ' . $e->getMessage());
            $this->tg->sendMessage(
                $this->chatId,
                "👋 Welcome to WCU RESULT!\n\nSomething went wrong. Please try again or contact support."
            );
            return self::END;
        }
    }

    private function beginAction(): string
    {
        if ($this->query !== null) {
            $this->answerQuery();
            $action = self::MENU_CALLBACK_ACTIONS[(string) ($this->query['data'] ?? '')] ?? null;
        } else {
            $action = self::MENU_TEXT_ACTIONS[strtolower(Text::strip((string) $this->messageText()))] ?? null;
        }

        if ($action === null) {
            $this->showMainMenu();
            return self::END;
        }

        if ($action === 'developer') {
            $this->renderPanel(self::DEVELOPER_TEXT, self::backMarkup());
            return self::END;
        }

        $this->data['pending_action'] = $action;
        $client = $this->getActiveSessionClient();
        if ($client !== null) {
            $this->executeAction($action, $client);
            return self::END;
        }

        $this->renderPanel('Enter your portal username (e.g., WCU174538):', self::backMarkup());
        return self::AUTH_USERNAME;
    }

    private function handleAuthUsername(): string
    {
        $username = strtoupper(Text::strip((string) $this->messageText()));
        $this->hideSensitiveUserMessage();
        if (!preg_match('/^WCU\d+$/', $username)) {
            $this->renderPanel('Invalid username format. Use format like WCU174538.', self::backMarkup());
            return self::AUTH_USERNAME;
        }

        $this->data['auth_username'] = $username;
        $this->renderPanel('Enter your portal password:', self::backMarkup());
        return self::AUTH_PASSWORD;
    }

    private function handleAuthPassword(): string
    {
        $password = Text::strip((string) $this->messageText());
        $this->hideSensitiveUserMessage();
        $username = $this->data['auth_username'] ?? null;
        $action = $this->data['pending_action'] ?? 'grade';
        if (!$username) {
            $this->showMainMenu();
            return self::END;
        }

        $this->renderPanel('Logging in...', self::backMarkup());
        $client = new PortalClient($username, $password);
        $error = $client->login();
        if ($error !== null) {
            $this->renderPanel("{$error}\nTry password again:", self::backMarkup());
            return self::AUTH_PASSWORD;
        }

        $this->storeSessionClient($client);
        unset($this->data['auth_username']);
        $this->renderPanel('✅ Login successful. Session active for 30 minutes.');
        if ($action === 'menu_home') {
            $this->showMainMenu();
            return self::END;
        }
        $this->executeAction($action, $client);
        return self::END;
    }

    private function cancel(): string
    {
        $this->data = [];
        $this->tg->sendMessage(
            $this->chatId,
            "❌ **Registration Canceled**\n\n"
            . "The registration process has been canceled.\n\n"
            . 'You can start over by typing /start or use /help for assistance.',
            self::removeKeyboard(),
            'Markdown'
        );
        return self::END;
    }

    private function executeAction(string $action, PortalClient $client): void
    {
        $this->clearActiveProfileMessage();

        if ($action === 'developer') {
            $this->renderPanel(self::DEVELOPER_TEXT, self::backMarkup());
            return;
        }

        if ($action === 'profile') {
            $profile = $client->getProfile();
            if (!is_array($profile)) {
                $this->renderPanel((string) $profile, self::backMarkup());
                return;
            }

            [$imageUrl, $caption] = $profile;
            $panelId = $this->data['panel_message_id'] ?? null;
            unset($this->data['panel_message_id']);
            if ($panelId) {
                $this->tg->deleteMessage($this->chatId, (int) $panelId);
            }

            $sent = null;
            if (!PortalClient::isPortalUrl($imageUrl)) {
                $sent = $this->tg->sendPhotoUrl($this->chatId, $imageUrl, $caption, self::backMarkup());
            }
            if ($sent === null) {
                $bytes = $client->download($imageUrl);
                if ($bytes !== null) {
                    $sent = $this->tg->sendPhotoBytes($this->chatId, $bytes, $caption, self::backMarkup());
                }
            }

            if ($sent !== null) {
                $this->data['active_profile_message_id'] = $sent['message_id'];
            } else {
                $this->renderPanel(
                    "👤 Profile\n\n{$caption}\n\n(Profile photo unavailable right now.)",
                    self::backMarkup()
                );
            }
            return;
        }

        if ($action === 'placement') {
            $this->renderPanel($client->getPlacedDepartment(), self::backMarkup());
            return;
        }

        if ($action === 'grade' || $action === 'courses') {
            $grades = $client->getGrades();
            if (is_string($grades)) {
                $this->renderPanel($grades, self::backMarkup());
                return;
            }
            if (!$grades) {
                $this->renderPanel('No grade information available.', self::backMarkup());
                return;
            }

            $entriesByYear = [];
            foreach ($grades as $raw) {
                $entriesByYear[Format::academicYear($raw)][] = [
                    'semester_label' => Format::semesterLabel($raw),
                    'raw' => $raw,
                ];
            }
            $years = array_map('strval', array_keys($entriesByYear));
            rsort($years, SORT_STRING);

            $this->data['available_years'] = $years;
            $this->data['entries_by_year'] = $entriesByYear;
            $this->data['selected_year'] = null;
            $this->sendYearMenu();
            return;
        }

        $this->renderPanel('Unknown action selected.', self::mainMenuMarkup());
    }

    private function backToMain(): void
    {
        $this->answerQuery();
        if (!empty($this->query['message']['photo'])) {
            $this->tg->deleteMessage($this->chatId, (int) $this->query['message']['message_id']);
        }
        unset($this->data['auth_username'], $this->data['pending_action']);
        $this->showMainMenu();
    }

    private function backToYears(): void
    {
        $this->answerQuery();
        $this->data['selected_year'] = null;
        $this->sendYearMenu();
    }

    private function handleYearSelection(): void
    {
        $this->answerQuery();
        $years = $this->data['available_years'] ?? [];
        $index = (int) substr((string) $this->query['data'], strlen('year_'));
        if (!isset($years[$index])) {
            $this->renderPanel('Invalid year selection. Try again.', self::mainMenuMarkup());
            return;
        }

        $year = (string) $years[$index];
        if (empty($this->data['entries_by_year'][$year])) {
            $this->renderPanel('No semester data found for this academic year.', self::mainMenuMarkup());
            return;
        }

        $this->data['selected_year'] = $year;
        $this->sendSemesterMenu();
    }

    private function handleSemesterSelection(): void
    {
        $this->answerQuery();
        $entries = $this->selectedEntries();
        $index = (int) substr((string) $this->query['data'], strlen('sem_'));
        if (!isset($entries[$index])) {
            $this->renderPanel('Invalid semester selection.', self::mainMenuMarkup());
            return;
        }

        $entry = $entries[$index];
        $raw = (string) ($entry['raw'] ?? '');
        $label = (string) ($entry['semester_label'] ?? 'Semester');
        $year = (string) ($this->data['selected_year'] ?? '');
        $body = ($this->data['pending_action'] ?? 'grade') === 'courses'
            ? Format::registeredCoursesText($raw)
            : Format::semesterText($raw);

        $this->renderPanel(
            Format::clamp($body . "\n\n🗓 {$year} • {$label}\n\n🤖 WCU RESULT"),
            ['inline_keyboard' => [
                [self::button('⬅️ Back', 'back_years')],
                [self::button('🏠 Menu', 'back_main')],
            ]]
        );
    }

    private function sendYearMenu(): void
    {
        $years = $this->data['available_years'] ?? [];
        if (!$years) {
            $this->renderPanel('No academic years found.', self::mainMenuMarkup());
            return;
        }

        $buttons = [];
        foreach ($years as $index => $year) {
            $buttons[] = [self::button("📅 {$year}", "year_{$index}")];
        }
        $buttons[] = [self::button('⬅️ Back', 'back_main')];

        $title = ($this->data['pending_action'] ?? 'grade') === 'grade'
            ? 'Select academic year for grade report:'
            : 'Select academic year for registered courses:';
        $this->renderPanel($title, ['inline_keyboard' => $buttons]);
    }

    private function sendSemesterMenu(): void
    {
        $entries = $this->selectedEntries();
        if (!$entries) {
            $this->renderPanel('No semester data found.', self::mainMenuMarkup());
            return;
        }

        $buttons = [];
        foreach ($entries as $index => $entry) {
            $buttons[] = [self::button((string) $entry['semester_label'], "sem_{$index}")];
        }
        $buttons[] = [self::button('⬅️ Back', 'back_years')];
        $buttons[] = [self::button('🏠 Menu', 'back_main')];

        $year = (string) ($this->data['selected_year'] ?? '');
        $title = ($this->data['pending_action'] ?? 'grade') === 'grade'
            ? "Select semester for grade report ({$year}):"
            : "Select semester for registered courses ({$year}):";
        $this->renderPanel($title, ['inline_keyboard' => $buttons]);
    }

    private function selectedEntries(): array
    {
        $year = $this->data['selected_year'] ?? null;
        if ($year === null) {
            return [];
        }
        return array_values($this->data['entries_by_year'][(string) $year] ?? []);
    }

    private function policy(): void
    {
        $user = (string) ($this->from['first_name'] ?? '');
        $this->tg->sendChatAction($this->chatId);
        $this->tg->sendMessage(
            $this->chatId,
            "🔒 **Privacy Policy**\n\n"
            . "Hello {$user},\n\n"
            . "At WCU RESULT, we take your privacy seriously. Here's how we handle your data:\n\n"
            . "1. **No Data Storage**: We do not store your student ID, password, or any personal information.\n"
            . "2. **Secure Communication**: All data is transmitted securely using encryption.\n"
            . "3. **Transparency**: Your records are fetched from the official WCU portal.\n\n"
            . 'If you have any concerns, feel free to reach out to the bot developer.',
            self::removeKeyboard(),
            'Markdown'
        );
    }

    private function about(): void
    {
        $this->tg->sendChatAction($this->chatId);
        $this->tg->sendMessage(
            $this->chatId,
            "🤖 **About WCU RESULT**\n\n"
            . 'WCU RESULT is designed to help students at Wachemo University access their '
            . "academic information quickly and securely.\n\n"
            . 'Portal: https://portal.wcu.edu.et/',
            self::removeKeyboard(),
            'Markdown'
        );
    }

    private function help(): void
    {
        $this->tg->sendChatAction($this->chatId);
        $this->tg->sendMessage(
            $this->chatId,
            "🆘 **Help**\n\n"
            . "Here are some commands you can use:\n\n"
            . "- /start: Start the bot and register.\n"
            . "- /help: Get help and instructions.\n"
            . "- /policy: Learn about our privacy policy.\n"
            . "- /about: Learn more about WCU RESULT.\n\n"
            . 'Portal: https://portal.wcu.edu.et/',
            self::removeKeyboard(),
            'Markdown'
        );
    }

    private function filterMedia(): void
    {
        if ($this->message === null) {
            return;
        }

        $template = null;
        foreach (self::MEDIA_REPLIES as $field => $reply) {
            if (!empty($this->message[$field])) {
                $template = $reply;
                break;
            }
        }
        if ($template === null) {
            foreach (self::ATTACHMENT_FIELDS as $field) {
                if (!empty($this->message[$field])) {
                    $template = "📎 Dear %s, I currently don't support processing attachments. ";
                    break;
                }
            }
        }
        if ($template === null) {
            return;
        }

        $this->tg->sendChatAction($this->chatId);
        $this->tg->sendMessage(
            $this->chatId,
            sprintf($template, (string) ($this->from['first_name'] ?? '')) . 'Please use text-based commands instead!',
            null,
            null,
            (int) $this->message['message_id']
        );
    }

    private function showMainMenu(): void
    {
        $this->renderPanel('Choose an option:', self::mainMenuMarkup());
    }

    /**
     * Show a single bot "panel" message, editing it in place when possible.
     */
    private function renderPanel(string $text, ?array $markup = null): void
    {
        if (isset($this->query['message']['message_id'])) {
            $messageId = (int) $this->query['message']['message_id'];
            if ($this->tg->editMessageText($this->chatId, $messageId, $text, $markup)) {
                $this->data['panel_message_id'] = $messageId;
                return;
            }
        }

        $panelId = $this->data['panel_message_id'] ?? null;
        if ($panelId && $this->tg->editMessageText($this->chatId, (int) $panelId, $text, $markup)) {
            return;
        }

        $sent = $this->tg->sendMessage($this->chatId, $text, $markup);
        if ($sent !== null) {
            $this->data['panel_message_id'] = $sent['message_id'];
        }
    }

    private function hideSensitiveUserMessage(): void
    {
        $text = $this->messageText();
        if ($text === null || Text::startsWith(Text::strip($text), '/start')) {
            return;
        }
        $this->tg->deleteMessage($this->chatId, (int) $this->message['message_id']);
    }

    private function clearActiveProfileMessage(): void
    {
        $messageId = $this->data['active_profile_message_id'] ?? null;
        unset($this->data['active_profile_message_id']);
        if ($messageId) {
            $this->tg->deleteMessage($this->chatId, (int) $messageId);
        }
    }

    private function getActiveSessionClient(): ?PortalClient
    {
        if ($this->portal === null || $this->portalExpiresAt < time()) {
            $this->portal = null;
            $this->portalExpiresAt = 0;
            $this->client = null;
            return null;
        }
        $this->portalExpiresAt = time() + self::SESSION_TTL_SECONDS;
        if ($this->client === null) {
            $this->client = PortalClient::fromState($this->portal);
        }
        return $this->client;
    }

    private function storeSessionClient(PortalClient $client): void
    {
        $this->client = $client;
        $this->portal = $client->toState();
        $this->portalExpiresAt = time() + self::SESSION_TTL_SECONDS;
    }

    private function persist(): void
    {
        if ($this->client !== null) {
            if ($this->client->isSessionExpired()) {
                $this->portal = null;
                $this->portalExpiresAt = 0;
            } else {
                $this->portal = $this->client->toState();
            }
        }
        $this->store->save($this->userId, $this->state, $this->data, $this->portal, $this->portalExpiresAt);
    }

    private function answerQuery(): void
    {
        if ($this->query !== null && empty($this->query['answered'])) {
            $this->tg->answerCallbackQuery((string) $this->query['id']);
            $this->query['answered'] = true;
        }
    }

    private function messageText(): ?string
    {
        return isset($this->message['text']) ? (string) $this->message['text'] : null;
    }

    /**
     * Whether the message starts with a bot command (optionally a specific one, e.g. "start").
     */
    private function isCommand(?string $name = null): bool
    {
        $text = $this->messageText();
        $entity = $this->message['entities'][0] ?? null;
        if ($text === null || !$entity || ($entity['type'] ?? '') !== 'bot_command' || (int) ($entity['offset'] ?? -1) !== 0) {
            return false;
        }
        if ($name === null) {
            return true;
        }
        $command = substr($text, 1, max(0, (int) $entity['length'] - 1));
        $command = strtolower(explode('@', $command, 2)[0]);
        return $command === $name;
    }

    private static function button(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => $callbackData];
    }

    private static function backMarkup(): array
    {
        return ['inline_keyboard' => [[self::button('⬅️ Back', 'back_main')]]];
    }

    private static function mainMenuMarkup(): array
    {
        return ['inline_keyboard' => [
            [self::button('📊 Grade Report', 'menu_grade')],
            [self::button('📚 Registered Courses', 'menu_courses')],
            [self::button('🏫 Placed Department', 'menu_placement')],
            [self::button('👤 View Profile', 'menu_profile')],
            [self::button('👨‍💻 Developer', 'menu_developer')],
        ]];
    }

    private static function removeKeyboard(): array
    {
        return ['remove_keyboard' => true];
    }
}
