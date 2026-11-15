<?php

declare(strict_types=1);

/**
 * Builds structured semesters from scraped text and turns them into Telegram-friendly messages.
 *
 * A semester is: ['title' => string, 'year' => '2025/26' or '', 'label' => 'Year II • Semester 2',
 * 'courses' => [['name', 'code', 'credit', 'ects', 'grade'], ...], 'summary' => ['SGPA' => '3.5', ...]].
 */
final class Format
{
    private const GRADES = ['A+', 'A', 'A-', 'B+', 'B', 'B-', 'C+', 'C', 'C-', 'D+', 'D', 'D-', 'F', 'PASS', 'FAIL'];
    private const SEMESTER_NAMES = [
        'one' => '1', 'two' => '2', 'three' => '3', 'i' => '1', 'ii' => '2', 'iii' => '3',
        'first' => '1', 'second' => '2', 'third' => '3', 'summer' => 'Summer',
    ];
    private const SUMMARY_LABELS = ['SGP', 'SGPA', 'CGP', 'CGPA'];

    public static function isCourseCode(string $value): bool
    {
        return preg_match('/(*UCP)^[A-Za-z]{2,}\d{3,4}$/Du', str_replace(' ', '', $value)) === 1;
    }

    public static function isGradeValue(string $value): bool
    {
        return in_array(strtoupper(Text::strip($value)), self::GRADES, true);
    }

    /**
     * An empty semester from its header, e.g. "Academic Year : 2025/26, Year II, Semester : Two".
     */
    public static function semester(string $title): array
    {
        $title = Text::strip(preg_replace('/(*UCP)\s+/u', ' ', $title));
        $year = self::search('/(*UCP)Academic\s*Year\s*:\s*(\d{4}\s*\/\s*\d{2,4})/iu', $title);
        $level = self::search('/(*UCP)(?:^|[,;|])\s*Year\s*:?\s*([IVX]+|\d+)\b/iu', $title);
        $term = self::search('/(*UCP)Semester\s*:?\s*([A-Za-z0-9]+)/iu', $title);

        $parts = [];
        if ($level !== null) {
            $parts[] = 'Year ' . strtoupper($level);
        }
        if ($term !== null) {
            $parts[] = 'Semester ' . (self::SEMESTER_NAMES[strtolower($term)] ?? $term);
        }
        return [
            'title' => $title,
            'year' => $year !== null ? str_replace(' ', '', $year) : '',
            'label' => $parts ? implode(' • ', $parts) : 'Semester',
            'courses' => [],
            'summary' => [],
        ];
    }

    /**
     * Values from a row like "SGP : 60.5 SGPA : 3.25 CGP : 282.25 CGPA : 3.1 Academic Status : Promoted".
     *
     * @return array<string, string>
     */
    public static function summary(string $text): array
    {
        $summary = [];
        foreach (self::SUMMARY_LABELS as $label) {
            $value = self::search('/(*UCP)\b' . $label . '\s*:\s*([\d.]+)/iu', $text);
            if ($value !== null) {
                $summary[$label] = $value;
            }
        }
        $status = self::search('/(*UCP)Academic\s*Status\s*:\s*([^\n:]+?)\s*(?:\b(?:SGP|SGPA|CGP|CGPA)\s*:|$)/iu', Text::strip($text));
        if ($status !== null && $status !== '') {
            $summary['Status'] = $status;
        }
        return $summary;
    }

    /**
     * A semester from raw cell text (one cell per line), for grade pages in an unknown layout.
     */
    public static function semesterFromRaw(string $raw): array
    {
        $lines = Text::lines($raw);
        $joined = implode(' ', $lines);
        $header = self::search('/(*UCP)(Academic\s*Year\s*:.*?Semester\s*:?\s*[A-Za-z0-9]+)/iu', $joined)
            ?? (self::search('/(*UCP)(Academic\s*Year\s*:\s*\d{4}\s*\/\s*\d{2,4})/iu', $joined) ?? '');
        $semester = self::semester($header);
        $semester['summary'] = self::summary($joined);

        $count = count($lines);
        $i = 0;
        while ($i < $count) {
            if ($i + 1 < $count && self::isCourseCode($lines[$i + 1])) {
                $grade = '';
                $step = 2;
                for ($j = $i + 2; $j < min($count, $i + 6); $j++) {
                    if (self::isGradeValue($lines[$j])) {
                        $grade = strtoupper(Text::strip($lines[$j]));
                        $step = $j - $i + 1;
                        break;
                    }
                    if ($j + 1 < $count && self::isCourseCode($lines[$j + 1])) {
                        break;
                    }
                }
                $semester['courses'][] = [
                    'name' => $lines[$i],
                    'code' => strtoupper(str_replace(' ', '', $lines[$i + 1])),
                    'credit' => '',
                    'ects' => '',
                    'grade' => $grade,
                ];
                $i += $step;
                continue;
            }
            $i++;
        }
        return $semester;
    }

    public static function semesterText(array $semester): string
    {
        $output = ['📚 Semester Result'];
        if ($semester['title'] !== '') {
            $output[] = "\n🗓 {$semester['title']}";
        }

        if ($semester['courses']) {
            $output[] = "\n📖 Courses";
            foreach ($semester['courses'] as $index => $course) {
                $output[] = ($index + 1) . ". {$course['name']}";
                $output[] = "   • Code: {$course['code']}";
                $hours = self::hours($course);
                if ($hours !== '') {
                    $output[] = "   • {$hours}";
                }
                $output[] = '   • Grade: ' . ($course['grade'] !== '' ? $course['grade'] : 'not released yet');
            }
            if (!self::hasGrades($semester)) {
                $output[] = "\nℹ️ The portal has not released grades for this semester yet.";
            }
        } else {
            $output[] = "\nNo courses listed for this semester.";
        }

        if ($semester['summary']) {
            $output[] = "\n📊 Summary";
            foreach ($semester['summary'] as $label => $value) {
                $output[] = "• {$label}: {$value}";
            }
        }
        return implode("\n", $output);
    }

    public static function registeredCoursesText(array $semester): string
    {
        $year = $semester['year'] !== '' ? $semester['year'] : 'Not shown';
        $output = ["📚 Registered Courses\n\n🗓 Academic Year: {$year}\n🧾 {$semester['label']}"];
        if (!$semester['courses']) {
            $output[] = "\nNo registered courses found for this semester.";
            return implode("\n", $output);
        }
        $output[] = "\n📖 Course List";
        foreach ($semester['courses'] as $index => $course) {
            $output[] = ($index + 1) . ". {$course['name']}";
            $output[] = "   • Code: {$course['code']}";
            $hours = self::hours($course);
            if ($hours !== '') {
                $output[] = "   • {$hours}";
            }
        }
        return implode("\n", $output);
    }

    public static function hasGrades(array $semester): bool
    {
        foreach ($semester['courses'] as $course) {
            if ($course['grade'] !== '') {
                return true;
            }
        }
        return false;
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

    private static function hours(array $course): string
    {
        $parts = [];
        if ($course['credit'] !== '') {
            $parts[] = "Credit: {$course['credit']}";
        }
        if ($course['ects'] !== '') {
            $parts[] = "ECTS: {$course['ects']}";
        }
        return implode(' | ', $parts);
    }

    private static function search(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $match) ? Text::strip($match[1]) : null;
    }
}
