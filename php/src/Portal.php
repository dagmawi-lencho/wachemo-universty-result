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
    private const DEFAULT_IMAGE = 'https://i.imgur.com/6zZ9b8Z.png';

    private const GRADE_MARKERS = ['academic status', 'cgpa', 'course', 'grade', 'result', 'semester'];
    private const PROFILE_MARKERS = ['full name', 'id no', 'department', 'year', 'profile'];
    private const PROFILE_PATTERNS = [
        'full name' => '/(*UCP)full\s*name\s*[:\-]\s*([^\n]+)/iu',
        'id no' => '/(*UCP)(?:id\s*no|id\s*number|student\s*id)\s*[:\-]\s*([^\n]+)/iu',
        'department' => '/(*UCP)department\s*[:\-]\s*([^\n]+)/iu',
        'year' => '/(*UCP)year\s*[:\-]\s*([^\n]+)/iu',
        'placed department' => '/(*UCP)(?:placed|placement)\s*department\s*[:\-]\s*([^\n]+)/iu',
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

            $finalUrl = $response['url'];
            $this->loginLinks = $this->collectLinks($response['body']);

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
                return $errorText !== '' ? $errorText : 'Invalid credentials.';
            }

            $finalLower = strtolower($finalUrl);
            if (Text::contains($finalLower, '/home') || Text::contains($finalLower, '/grade/gradereport')) {
                $this->loggedIn = true;
                return null;
            }
            if (self::isLoginUrl($finalUrl)) {
                return 'Incorrect username or password.';
            }

            $this->loggedIn = true;
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
     * @return array{0: string, 1: string}|string [image URL, profile details] or an error message.
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
            if ($response['status'] !== 200) {
                return "Profile fetch failed: HTTP {$response['status']}.";
            }
            if (self::isLoginUrl($response['url'])) {
                $this->sessionExpired = true;
                return 'Profile fetch failed: session expired or login failed.';
            }

            if (!self::looksLike($response['body'], self::PROFILE_MARKERS)) {
                $candidate = $this->findCandidatePage(['home', 'profile', 'dashboard', 'student'], self::PROFILE_MARKERS);
                if ($candidate !== null) {
                    $response = $candidate;
                }
            }

            [$dom, $xpath] = Html::load($response['body']);

            $image = Html::firstWithClass($xpath, 'img', 'img-rounded');
            $src = $image !== null ? $image->getAttribute('src') : '';
            if ($src === '') {
                $imageUrl = self::DEFAULT_IMAGE;
            } elseif (Text::startsWith($src, 'http://') || Text::startsWith($src, 'https://')) {
                $imageUrl = $src;
            } else {
                $imageUrl = rtrim(self::BASE_URL, '/') . '/' . ltrim($src, '/');
            }

            $data = $this->extractProfileFields($dom, $xpath);
            $details = 'Full Name: ' . self::firstAvailable($data, ['full name', 'student name', 'name', 'applicant name']) . "\n"
                . 'ID No.: ' . self::firstAvailable($data, ['id no', 'id number', 'student id', 'idno']) . "\n"
                . 'Department: ' . self::firstAvailable($data, ['department', 'placed department', 'placement department', 'program', 'stream']) . "\n"
                . 'Year: ' . self::firstAvailable($data, ['year', 'batch', 'academic year', 'study year']);

            $this->cachedProfile = [$imageUrl, $details];
            return $this->cachedProfile;
        } catch (Throwable $e) {
            return 'Profile fetch failed: ' . $e->getMessage();
        }
    }

    /**
     * @return string[]|string Raw semester blocks, or an error message.
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
            if ($response['status'] !== 200) {
                return "Grades fetch failed: HTTP {$response['status']}.";
            }
            if (self::isLoginUrl($response['url'])) {
                $this->sessionExpired = true;
                return 'Grades fetch failed: session expired or login failed.';
            }

            if (!self::looksLike($response['body'], self::GRADE_MARKERS)) {
                $candidate = $this->findCandidatePage(['grade', 'result', 'transcript', 'report'], self::GRADE_MARKERS);
                if ($candidate !== null) {
                    $response = $candidate;
                }
            }

            [, $xpath] = Html::load($response['body']);
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

            $this->cachedGrades = $semesters ?: ['No grades available yet.'];
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

        $endpoints = [self::BASE_URL . 'Home', self::BASE_URL . 'NewStudents/Profile', self::BASE_URL . 'NewStudents/Welcome'];
        foreach ($endpoints as $endpoint) {
            try {
                $response = $this->request($endpoint);
            } catch (PortalException $e) {
                continue;
            }
            if ($response['status'] !== 200 || self::isLoginUrl($response['url'])) {
                continue;
            }

            [$dom, $xpath] = Html::load($response['body']);
            $data = $this->extractProfileFields($dom, $xpath);
            $department = self::firstAvailable(
                $data,
                ['placed department', 'placement department', 'department', 'stream', 'program'],
                ''
            );
            if ($department !== '') {
                $this->cachedPlacement = '🏫 Placed Department: ' . $department;
                return $this->cachedPlacement;
            }
        }

        $this->cachedPlacement = "I couldn't find your placed department yet. It may not be published on the portal.";
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
        return $response['status'] === 200 && $response['body'] !== '' ? $response['body'] : null;
    }

    public static function isPortalUrl(string $url): bool
    {
        return Html::host($url) === Html::host(self::BASE_URL);
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

    private static function isLoginUrl(string $url): bool
    {
        return Text::contains(strtolower($url), '/login');
    }

    /**
     * @param string[] $markers
     */
    private static function looksLike(string $html, array $markers): bool
    {
        $lowered = strtolower($html);
        foreach ($markers as $marker) {
            if (Text::contains($lowered, $marker)) {
                return true;
            }
        }
        return false;
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
            $links[$full] = true;
        }
        return array_keys($links);
    }

    /**
     * Walk links found on the post-login page (preferred keywords first) until one looks right.
     *
     * @param string[] $prefer
     * @param string[] $markers
     */
    private function findCandidatePage(array $prefer, array $markers): ?array
    {
        $preferred = [];
        $others = [];
        foreach ($this->loginLinks as $link) {
            $lowered = strtolower($link);
            $matched = false;
            foreach ($prefer as $keyword) {
                if (Text::contains($lowered, $keyword)) {
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

        foreach (array_merge($preferred, $others) as $link) {
            try {
                $response = $this->request($link);
            } catch (PortalException $e) {
                continue;
            }
            if ($response['status'] === 200 && self::looksLike($response['body'], $markers)) {
                return $response;
            }
        }
        return null;
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
    private function extractProfileFields(DOMDocument $dom, DOMXPath $xpath): array
    {
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

        foreach (Html::query($xpath, '//label|//strong|//b|//span|//div|//p') as $tag) {
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
