<?php

declare(strict_types=1);

final class PortalException extends RuntimeException
{
    public const TIMEOUT = 1;
    public const CONNECTION = 2;
}

/**
 * Client for the WCU student portal. One instance holds one logged-in cookie session;
 * toState()/fromState() let the session survive between webhook requests.
 */
final class PortalClient
{
    public const BASE_URL = 'https://portal.wcu.edu.et/';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36';
    private const TIMEOUT = 12;
    private const MAX_CANDIDATE_PAGES = 8;

    private const GRADE_KEYWORDS = ['grade', 'result', 'transcript', 'report', 'academic'];
    private const PROFILE_KEYWORDS = ['profile', 'home', 'dashboard', 'student', 'basic', 'info'];
    /** Links the candidate search must never open: they end the session or change data. */
    private const UNSAFE_LINK_PATTERN = '/log\s*off|log-?out|sign-?out|delete|remove|drop|cancel|withdraw/i';
    /** Public areas that are not tied to the logged-in student. */
    private const PUBLIC_LINK_PATTERN = '#/(newstudents|web|account)(/|$)#i';

    private const PROFILE_PATTERNS = [
        'full name' => '/(*UCP)full\s*name\s*[:\-]\s*([^\n]+)/iu',
        'id no' => '/(*UCP)(?:id\s*no|id\s*number|student\s*id)\s*[:\-]\s*([^\n]+)/iu',
        'department' => '/(*UCP)department\s*[:\-]\s*([^\n]+)/iu',
        'year' => '/(*UCP)year\s*[:\-]\s*([^\n]+)/iu',
        'placed department' => '/(*UCP)(?:placed|placement)\s*department\s*[:\-]\s*([^\n]+)/iu',
    ];
    private const PROFILE_ALIASES = [
        'name' => ['full name', 'student name', 'name', 'applicant name'],
        'id' => ['id no', 'id number', 'student id', 'idno'],
        'department' => ['department', 'placed department', 'placement department', 'program', 'stream'],
        'year' => ['year', 'batch', 'academic year', 'study year'],
    ];
    private const SKIP_VALUES = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '2.00', '3.00', '4.00', '5.00'];

    private string $studentId;
    private string $password;
    /** @var resource|CurlHandle|null */
    private $curl = null;
    /** @var string[] */
    private array $cookies = [];
    private bool $loggedIn = false;
    private bool $sessionExpired = false;
    /** @var string[] */
    private array $loginLinks = [];
    private ?array $cachedProfile = null;
    private ?array $cachedGrades = null;
    private ?string $cachedPlacement = null;

    public function __construct(string $studentId, string $password)
    {
        $this->studentId = $studentId;
        $this->password = $password;
    }

    public static function fromState(array $state): self
    {
        $client = new self((string) ($state['student_id'] ?? ''), '');
        $client->cookies = $state['cookies'] ?? [];
        $client->loggedIn = (bool) ($state['logged_in'] ?? false);
        $client->loginLinks = $state['login_links'] ?? [];
        $client->cachedProfile = $state['profile'] ?? null;
        $client->cachedGrades = $state['grades'] ?? null;
        $client->cachedPlacement = $state['placement'] ?? null;
        return $client;
    }

    /**
     * Serializable session state. The password is deliberately not included.
     */
    public function toState(): array
    {
        return [
            'student_id' => $this->studentId,
            'cookies' => $this->exportCookies(),
            'logged_in' => $this->loggedIn,
            'login_links' => $this->loginLinks,
            'profile' => $this->cachedProfile,
            'grades' => $this->cachedGrades,
            'placement' => $this->cachedPlacement,
        ];
    }

    public function isSessionExpired(): bool
    {
        return $this->sessionExpired;
    }

    /**
     * @return string|null null on success, otherwise an error message for the user.
     */
    public function login(): ?string
    {
        try {
            $page = $this->request(self::BASE_URL);
            [, $xpath] = Html::load($page['body']);

            $payload = [
                'UserName' => $this->studentId,
                'Password' => $this->password,
                'button' => 'login',
            ];
            foreach (Html::query($xpath, '//input') as $input) {
                if ($input->getAttribute('type') !== 'hidden') {
                    continue;
                }
                $name = $input->getAttribute('name');
                if ($name !== '') {
                    $payload[$name] = $input->getAttribute('value');
                }
            }

            $forms = Html::query($xpath, '//form');
            $action = $forms ? $forms[0]->getAttribute('action') : '/login';
            $response = $this->request(Html::urljoin(self::BASE_URL, $action), $payload);

            [, $errorXpath] = Html::load($response['body']);
            $errorElement = Html::firstWithClass($errorXpath, 'span', 'field-validation-error')
                ?? Html::firstWithClass($errorXpath, 'div', 'validation-summary-errors')
                ?? Html::firstWithClass($errorXpath, 'span', 'text-danger')
                ?? Html::firstWithClass($errorXpath, 'div', 'text-danger')
                ?? $this->findIncorrectListItem($errorXpath);
            if ($errorElement !== null) {
                $errorText = Html::text($errorElement);
                if (Text::contains(strtolower($errorText), 'locked')) {
                    return 'Your account has been locked out for 15 minutes due to multiple failed login attempts.';
                }
                if (Text::contains($errorText, 'attempt')) {
                    return $errorText;
                }
                if ($errorText !== '' || self::isLoginPage($response)) {
                    return $errorText !== '' ? $errorText : 'Invalid credentials.';
                }
            }

            if (self::isLoginPage($response)) {
                return 'Incorrect username or password.';
            }

            $this->loggedIn = true;
            $this->loginLinks = $this->collectLinks($response['body']);
            return null;
        } catch (PortalException $e) {
            if ($e->getCode() === PortalException::TIMEOUT) {
                return 'Failed to connect to the portal: request timed out.';
            }
            return 'Failed to connect to the portal: connection error.';
        } catch (Throwable $e) {
            return 'Login failed: ' . $e->getMessage();
        }
    }

    /**
     * @return array{0: ?string, 1: string}|string [photo URL or null, profile details] or a message for the user.
     */
    public function getProfile()
    {
        if ($this->cachedProfile !== null) {
            return $this->cachedProfile;
        }
        if (!$this->loggedIn) {
            $error = $this->login();
            if ($error !== null) {
                return $error;
            }
        }

        try {
            $response = $this->request(self::BASE_URL . 'Home');
            $problem = $this->checkResponse($response, 'Profile');
            if ($problem !== null) {
                return $problem;
            }

            $profile = $this->parseProfile($response['body']);
            if ($profile === null) {
                $found = $this->searchLinks($response['body'], self::PROFILE_KEYWORDS, function (string $html): bool {
                    return $this->parseProfile($html) !== null;
                });
                if ($this->sessionExpired) {
                    return 'Profile fetch failed: session expired or login failed.';
                }
                if ($found === null) {
                    Diagnostics::record('profile-unreadable', self::describe($response));
                    return self::unreadableMessage('profile', $response);
                }
                $profile = $this->parseProfile($found['body']);
            }

            $this->cachedProfile = $profile;
            return $this->cachedProfile;
        } catch (Throwable $e) {
            return 'Profile fetch failed: ' . $e->getMessage();
        }
    }

    /**
     * @return string[]|string Raw semester blocks, or a message for the user.
     */
    public function getGrades()
    {
        if ($this->cachedGrades !== null) {
            return $this->cachedGrades;
        }
        if (!$this->loggedIn) {
            $error = $this->login();
            if ($error !== null) {
                return $error;
            }
        }

        try {
            $response = $this->request(self::BASE_URL . 'Grade/GradeReport');
            $problem = $this->checkResponse($response, 'Grades');
            if ($problem !== null) {
                return $problem;
            }

            $semesters = self::parseSemesters($response['body']);
            if (!self::hasGradeContent($semesters)) {
                $found = $this->searchLinks($response['body'], self::GRADE_KEYWORDS, function (string $html): bool {
                    return self::hasGradeContent(self::parseSemesters($html));
                });
                if ($this->sessionExpired) {
                    return 'Grades fetch failed: session expired or login failed.';
                }
                if ($found === null) {
                    Diagnostics::record('grades-unreadable', self::describe($response));
                    return self::unreadableMessage('grade report', $response);
                }
                $semesters = self::parseSemesters($found['body']);
            }

            $this->cachedGrades = $semesters;
            return $this->cachedGrades;
        } catch (Throwable $e) {
            return 'Grades fetch failed: ' . $e->getMessage();
        }
    }

    public function getPlacedDepartment(): string
    {
        if ($this->cachedPlacement !== null) {
            return $this->cachedPlacement;
        }
        if (!$this->loggedIn) {
            $error = $this->login();
            if ($error !== null) {
                return $error;
            }
        }

        try {
            $response = $this->request(self::BASE_URL . 'Home');
        } catch (PortalException $e) {
            return 'Failed to connect to the portal. Please try again.';
        }
        $problem = $this->checkResponse($response, 'Placement');
        if ($problem !== null) {
            return $problem;
        }

        $data = $this->extractProfileFields($response['body']);
        $department = self::firstAvailable(
            $data,
            ['placed department', 'placement department', 'department', 'stream', 'program'],
            ''
        );
        if ($department === '') {
            return "I couldn't find a placed department on your portal account yet.\n\n"
                . 'Freshman placement results are published at https://portal.wcu.edu.et/NewStudents/DepartmentPlacementStatus';
        }

        $this->cachedPlacement = '🏫 Placed Department: ' . $department;
        return $this->cachedPlacement;
    }

    /**
     * Download a file (e.g. the profile photo) using the logged-in session.
     */
    public function download(string $url): ?string
    {
        try {
            $response = $this->request($url, null, 20);
        } catch (PortalException $e) {
            return null;
        }
        if ($response['status'] !== 200 || $response['body'] === '' || self::isLoginPage($response)) {
            return null;
        }
        return $response['body'];
    }

    public static function isPortalUrl(string $url): bool
    {
        return Html::host($url) === Html::host(self::BASE_URL);
    }

    /**
     * Raw semester blocks from a grade report page: table cells grouped until a CGPA/status cell.
     *
     * @return string[]
     */
    public static function parseSemesters(string $html): array
    {
        [, $xpath] = Html::load($html);
        $semesters = [];
        $current = [];
        foreach (Html::query($xpath, '//td') as $cell) {
            $text = Html::text($cell);
            if ($text === '' || in_array($text, self::SKIP_VALUES, true) || Text::contains($text, 'Assessment')) {
                continue;
            }
            $current[] = $text;
            if (Text::contains($text, 'Academic Status') || Text::contains($text, 'CGPA')) {
                $semesters[] = implode("\n", $current);
                $current = [];
            }
        }
        if ($current) {
            $semesters[] = implode("\n", $current);
        }
        return $semesters;
    }

    /**
     * @param string[] $semesters
     */
    public static function hasGradeContent(array $semesters): bool
    {
        foreach ($semesters as $semester) {
            if (preg_match('/Academic\s*Year|CGPA|SGPA|Academic\s*Status/i', $semester)) {
                return true;
            }
            foreach (Text::lines($semester) as $line) {
                if (Format::isCourseCode($line)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array{0: ?string, 1: string}|null null when the page has no student profile fields.
     */
    public function parseProfile(string $html): ?array
    {
        $data = $this->extractProfileFields($html);
        $values = [];
        foreach (self::PROFILE_ALIASES as $field => $aliases) {
            $values[$field] = self::firstAvailable($data, $aliases, '');
        }
        if ($values['name'] === '' && $values['id'] === '') {
            return null;
        }

        $details = 'Full Name: ' . ($values['name'] !== '' ? $values['name'] : 'N/A') . "\n"
            . 'ID No.: ' . ($values['id'] !== '' ? $values['id'] : 'N/A') . "\n"
            . 'Department: ' . ($values['department'] !== '' ? $values['department'] : 'N/A') . "\n"
            . 'Year: ' . ($values['year'] !== '' ? $values['year'] : 'N/A');
        return [$this->findPhotoUrl($html), $details];
    }

    /**
     * @param array<string, string>|null $post
     * @return array{status: int, url: string, body: string}
     */
    private function request(string $url, ?array $post = null, int $timeout = self::TIMEOUT): array
    {
        $curl = $this->curl();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => $timeout,
            CURLOPT_TIMEOUT => $timeout * 4,
        ]);
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        } else {
            curl_setopt($curl, CURLOPT_HTTPGET, true);
        }

        $body = curl_exec($curl);
        if ($body === false) {
            $errno = curl_errno($curl);
            throw new PortalException(
                curl_error($curl),
                $errno === CURLE_OPERATION_TIMEDOUT ? PortalException::TIMEOUT : PortalException::CONNECTION
            );
        }

        return [
            'status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'url' => (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL),
            'body' => (string) $body,
        ];
    }

    /**
     * @return resource|CurlHandle
     */
    private function curl()
    {
        if ($this->curl !== null) {
            return $this->curl;
        }
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Referer: ' . self::BASE_URL, 'Accept: */*'],
            CURLOPT_COOKIEFILE => '',
        ]);
        foreach ($this->cookies as $cookie) {
            curl_setopt($curl, CURLOPT_COOKIELIST, $cookie);
        }
        $this->curl = $curl;
        return $curl;
    }

    /**
     * @return string[]
     */
    private function exportCookies(): array
    {
        if ($this->curl === null) {
            return $this->cookies;
        }
        $list = curl_getinfo($this->curl, CURLINFO_COOKIELIST);
        return is_array($list) ? array_values($list) : [];
    }

    /**
     * Common checks for a page fetched with the session; returns a user message when it is unusable.
     */
    private function checkResponse(array $response, string $what): ?string
    {
        if (self::isLoginPage($response)) {
            $this->sessionExpired = true;
            return "{$what} fetch failed: session expired or login failed. Please log in again.";
        }
        if ($response['status'] !== 200) {
            return "{$what} fetch failed: HTTP {$response['status']}. The portal may be busy, please try again.";
        }
        return null;
    }

    /**
     * Whether a response is the portal's login form (redirected there, or the form itself).
     */
    private static function isLoginPage(array $response): bool
    {
        $path = strtolower((string) parse_url($response['url'], PHP_URL_PATH));
        if (preg_match('#/(account/)?login$#', rtrim($path, '/'))) {
            return true;
        }
        $body = $response['body'];
        return preg_match('/<form[^>]+action=["\'][^"\']*login[^"\']*["\']/i', $body) === 1
            && preg_match('/<input[^>]+name=["\']?UserName\b/i', $body) === 1
            && preg_match('/<input[^>]+type=["\']?password\b/i', $body) === 1;
    }

    /**
     * Explain to the student what the portal showed instead of the expected page.
     */
    private static function unreadableMessage(string $what, array $response): string
    {
        $info = self::describe($response);
        $title = $info['title'] !== '' ? $info['title'] : ($info['headings'][0] ?? $info['path']);
        $haystack = strtolower($info['path'] . ' ' . $info['title'] . ' ' . implode(' ', $info['headings']) . ' ' . implode(' ', $info['forms']));

        if (preg_match('/change\s*password|changepassword|reset\s*password/', $haystack)) {
            return "🔐 The portal is asking you to change your password first.\n\n"
                . "Open https://portal.wcu.edu.et/ in a browser, log in, change your password, then come back and log in here with the new password.";
        }
        if (preg_match('/evaluat|survey|questionnaire/', $haystack)) {
            return "📝 The portal requires you to complete the instructor evaluation first.\n\n"
                . 'Open https://portal.wcu.edu.et/ in a browser, finish the evaluation, then try again here.';
        }
        if (preg_match('/payment|fee|debt|clearance|cost\s*sharing/', $haystack)) {
            return "💳 The portal is holding your {$what} (payment or clearance).\n\n"
                . 'Open https://portal.wcu.edu.et/ in a browser to see what is required, then try again here.';
        }
        if ($what === 'grade report' && preg_match('/grade|result|transcript/', $haystack)) {
            return "📭 No grades have been published on the portal for your account yet.\n\n"
                . 'Please check again after results are released.';
        }
        return "⚠️ I couldn't find your {$what} on the portal. The portal showed the page \"{$title}\" instead.\n\n"
            . 'Open https://portal.wcu.edu.et/ in a browser to check whether something needs your attention, then try again here.';
    }

    /**
     * Structure of a page for diagnostics: no table contents or form values.
     */
    private static function describe(array $response): array
    {
        [, $xpath] = Html::load($response['body']);
        $titles = Html::query($xpath, '//title');
        $headings = [];
        foreach (Html::query($xpath, '//h1|//h2|//h3|//h4|//h5') as $heading) {
            $text = Html::text($heading, ' ');
            if ($text !== '') {
                $headings[] = Text::slice($text, 80);
            }
            if (count($headings) >= 8) {
                break;
            }
        }
        $forms = [];
        foreach (Html::query($xpath, '//form') as $form) {
            $forms[] = $form->getAttribute('action');
        }
        $links = [];
        foreach (Html::query($xpath, '//a[@href]') as $anchor) {
            $path = (string) parse_url(Html::urljoin(self::BASE_URL, trim($anchor->getAttribute('href'))), PHP_URL_PATH);
            if ($path !== '' && !in_array($path, $links, true)) {
                $links[] = $path;
            }
            if (count($links) >= 30) {
                break;
            }
        }
        $url = parse_url($response['url']);
        return [
            'status' => $response['status'],
            'path' => ($url['path'] ?? '') . (isset($url['query']) ? '?' . $url['query'] : ''),
            'title' => $titles ? Text::slice(Html::text($titles[0], ' '), 120) : '',
            'headings' => $headings,
            'forms' => $forms,
            'tables' => count(Html::query($xpath, '//table')),
            'cells' => count(Html::query($xpath, '//td')),
            'links' => $links,
        ];
    }

    /**
     * Same-host http(s) links on a page, in document order, without duplicates.
     *
     * @return string[]
     */
    private function collectLinks(string $html): array
    {
        [, $xpath] = Html::load($html);
        $baseHost = Html::host(self::BASE_URL);
        $links = [];
        foreach (Html::query($xpath, '//a[@href]') as $anchor) {
            $full = Html::urljoin(self::BASE_URL, trim($anchor->getAttribute('href')));
            $scheme = strtolower((string) parse_url($full, PHP_URL_SCHEME));
            if ($scheme !== 'http' && $scheme !== 'https') {
                continue;
            }
            $host = Html::host($full);
            if ($host !== '' && !Text::contains($host, $baseHost)) {
                continue;
            }
            $links[strtok($full, '#')] = true;
        }
        return array_keys($links);
    }

    /**
     * Open links from the given page and the post-login page (keyword matches first)
     * until one passes $accept. Never opens logout/destructive or public links.
     *
     * @param string[] $prefer
     */
    private function searchLinks(string $html, array $prefer, callable $accept): ?array
    {
        $tried = [
            strtolower(self::BASE_URL . 'Home') => true,
            strtolower(self::BASE_URL . 'Grade/GradeReport') => true,
            strtolower(self::BASE_URL) => true,
        ];
        $preferred = [];
        $others = [];
        foreach (array_merge($this->collectLinks($html), $this->loginLinks) as $link) {
            $key = strtolower(rtrim($link, '/'));
            $path = (string) parse_url($link, PHP_URL_PATH);
            if (isset($tried[$key]) || isset($tried[$key . '/'])
                || preg_match(self::UNSAFE_LINK_PATTERN, $link)
                || preg_match(self::PUBLIC_LINK_PATTERN, $path)) {
                continue;
            }
            $tried[$key] = true;
            $matched = false;
            foreach ($prefer as $keyword) {
                if (Text::contains(strtolower($link), $keyword)) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $preferred[] = $link;
            } else {
                $others[] = $link;
            }
        }

        foreach (array_slice(array_merge($preferred, $others), 0, self::MAX_CANDIDATE_PAGES) as $link) {
            try {
                $response = $this->request($link);
            } catch (PortalException $e) {
                continue;
            }
            if (self::isLoginPage($response)) {
                $this->sessionExpired = true;
                return null;
            }
            if ($response['status'] === 200 && $accept($response['body'])) {
                return $response;
            }
        }
        return null;
    }

    private function findPhotoUrl(string $html): ?string
    {
        [, $xpath] = Html::load($html);
        $src = '';
        $image = Html::firstWithClass($xpath, 'img', 'img-rounded');
        if ($image !== null) {
            $src = trim($image->getAttribute('src'));
        }
        if ($src === '') {
            foreach (Html::query($xpath, '//img[@src]') as $candidate) {
                $value = trim($candidate->getAttribute('src'));
                if (preg_match('/photo|picture|avatar|student/i', $value) && !preg_match('/logo/i', $value)) {
                    $src = $value;
                    break;
                }
            }
        }
        if ($src === '' || Text::startsWith($src, 'data:')) {
            return null;
        }
        if (Text::startsWith($src, 'http://') || Text::startsWith($src, 'https://')) {
            return $src;
        }
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($src, '/');
    }

    private function findIncorrectListItem(DOMXPath $xpath): ?DOMElement
    {
        foreach (Html::query($xpath, '//li') as $item) {
            $string = Html::singleString($item);
            if ($string !== null && Text::contains(strtolower($string), 'incorrect')) {
                return $item;
            }
        }
        return null;
    }

    private static function normalizeLabel(string $label): string
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($label)));
        return (string) preg_replace('/\s+/', ' ', $normalized);
    }

    /**
     * Profile fields from table rows, "Label: Value" blocks, or plain text.
     *
     * @return array<string, string>
     */
    private function extractProfileFields(string $html): array
    {
        [$dom, $xpath] = Html::load($html);
        $extracted = [];

        foreach (Html::query($xpath, '//tr') as $row) {
            $cells = Html::query($xpath, './/td|.//th', $row);
            if (count($cells) >= 2) {
                $key = self::normalizeLabel(Html::text($cells[0], ' '));
                $value = Html::text($cells[1], ' ');
                if ($key !== '' && $value !== '') {
                    $extracted[$key] = $value;
                }
            }
        }

        foreach (Html::query($xpath, '//label|//strong|//b|//span|//div|//p|//dt|//li') as $tag) {
            $text = Html::text($tag, ' ');
            $colon = strpos($text, ':');
            if ($colon === false) {
                continue;
            }
            $key = self::normalizeLabel(substr($text, 0, $colon));
            $value = Text::strip(substr($text, $colon + 1));
            if ($key !== '' && $value !== '') {
                $extracted[$key] = $value;
            }
        }

        foreach (Html::query($xpath, '//dt') as $term) {
            $next = $term->nextSibling;
            while ($next !== null && !($next instanceof DOMElement)) {
                $next = $next->nextSibling;
            }
            if ($next instanceof DOMElement && strtolower($next->nodeName) === 'dd') {
                $key = self::normalizeLabel(Html::text($term, ' '));
                $value = Html::text($next, ' ');
                if ($key !== '' && $value !== '') {
                    $extracted[$key] = $value;
                }
            }
        }

        $bodyText = Html::text($dom, "\n");
        foreach (self::PROFILE_PATTERNS as $key => $pattern) {
            if (!isset($extracted[$key]) && preg_match($pattern, $bodyText, $match)) {
                $extracted[$key] = Text::strip($match[1]);
            }
        }

        return $extracted;
    }

    /**
     * @param array<string, string> $data
     * @param string[] $aliases
     */
    private static function firstAvailable(array $data, array $aliases, string $default = 'N/A'): string
    {
        foreach ($aliases as $alias) {
            if (isset($data[$alias]) && $data[$alias] !== '') {
                return (string) $data[$alias];
            }
        }
        return $default;
    }
}
