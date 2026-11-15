<?php

declare(strict_types=1);

/**
 * Turns raw scraped semester text into Telegram-friendly messages.
 */
final class Format
{
    private const GRADES = ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F', 'PASS', 'FAIL'];
    private const SEMESTER_NAMES = [
        'one' => 'Semester 1',
        'two' => 'Semester 2',
        'i' => 'Semester 1',
        'ii' => 'Semester 2',
        '1' => 'Semester 1',
        '2' => 'Semester 2',
    ];

    public static function isCourseCode(string $value): bool
    {
        return preg_match('/(*UCP)^[A-Za-z]{2,}\d{3,4}$/Du', str_replace(' ', '', $value)) === 1;
    }

    public static function isGradeValue(string $value): bool
    {
        return in_array(strtoupper(Text::strip($value)), self::GRADES, true);
    }

    public static function semesterText(string $raw): string
    {
        $lines = Text::lines($raw);
        if (!$lines) {
            return 'No semester data found.';
        }
        $joined = implode(' ', $lines);

        $academic = self::search('/(Academic Year:[^A]+(?:Semester:[^\n]+)?)/iu', $joined);
        $stats = [];
        foreach (['SGP', 'SGPA', 'CGP', 'CGPA'] as $label) {
            $value = self::search('/(*UCP)' . $label . '\s*:\s*([\d.]+)/iu', $joined);
            if ($value !== null) {
                $stats[] = "{$label}: {$value}";
            }
        }
        $status = self::search('/(*UCP)Academic Status\s*:\s*([A-Za-z ]+)/iu', $joined);
        if ($status !== null) {
            $stats[] = 'Status: ' . Text::strip($status);
        }

        $courses = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            if ($i + 1 < $count && self::isCourseCode($lines[$i + 1])) {
                $grade = '-';
                $step = 2;
                if ($i + 2 < $count && self::isGradeValue($lines[$i + 2])) {
                    $grade = strtoupper($lines[$i + 2]);
                    $step = 3;
                }
                $courses[] = [$lines[$i], strtoupper(str_replace(' ', '', $lines[$i + 1])), $grade];
                $i += $step;
                continue;
            }
            $i++;
        }

        $output = ['📚 Semester Result'];
        if ($academic !== null) {
            $output[] = "\n🗓 " . Text::strip($academic);
        }
        if ($courses) {
            $output[] = "\n📖 Courses";
            foreach ($courses as $index => [$name, $code, $grade]) {
                $output[] = ($index + 1) . ". {$name}";
                $output[] = "   • Code: {$code}";
                $output[] = "   • Grade: {$grade}";
            }
        } else {
            $output[] = "\nNo course rows parsed from this page.";
        }
        if ($stats) {
            $output[] = "\n📊 Summary";
            foreach ($stats as $item) {
                $output[] = "• {$item}";
            }
        }

        return implode("\n", $output);
    }

    public static function registeredCoursesText(string $raw): string
    {
        $lines = Text::lines($raw);
        $year = self::academicYear($raw);
        $semester = self::semesterLabel($raw);

        $courses = [];
        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            if ($i + 1 < $count && self::isCourseCode($lines[$i + 1])) {
                $courses[] = [$lines[$i], strtoupper(str_replace(' ', '', $lines[$i + 1]))];
                $i += 2;
                continue;
            }
            $i++;
        }

        $output = ["📚 Registered Courses\n\n🗓 Academic Year: {$year}\n🧾 {$semester}"];
        if (!$courses) {
            $output[] = "\nNo registered courses found for this semester.";
            return implode("\n", $output);
        }
        $output[] = "\n📖 Course List";
        foreach ($courses as $index => [$name, $code]) {
            $output[] = ($index + 1) . ". {$name}";
            $output[] = "   • Code: {$code}";
        }
        return implode("\n", $output);
    }

    public static function academicYear(string $raw): string
    {
        $year = self::search('/(*UCP)Academic Year\s*:\s*(\d{4}\s*\/\s*\d{2,4})/iu', $raw);
        return $year !== null ? str_replace(' ', '', $year) : 'Other';
    }

    public static function semesterLabel(string $raw): string
    {
        $value = self::search('/(*UCP)Semester\s*:\s*([A-Za-z0-9]+)/iu', $raw);
        if ($value === null) {
            return 'Semester';
        }
        $value = Text::strip($value);
        return self::SEMESTER_NAMES[strtolower($value)] ?? "Semester {$value}";
    }

    /**
     * Keep messages under Telegram's 4096-character limit.
     */
    public static function clamp(string $text, int $limit = 3900): string
    {
        if (Text::length($text) <= $limit) {
            return $text;
        }
        return Text::slice($text, $limit - 30) . "\n\n...truncated for Telegram limit.";
    }

    private static function search(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $match) ? $match[1] : null;
    }
}
