#!/usr/bin/env php
<?php

declare(strict_types=1);

const TIMETABLE_URL = 'https://fortee.jp/iosdc-japan-2026/api/timetable';
const TARGET_TRACK = 'アンカンファレンス (@もくもくルーム)';
const OUTPUT_FILE = __DIR__ . '/iosdc2026_Unconference.ics';

/** Escape a value according to RFC 5545's TEXT rules. */
function escapeIcalText(string $value): string
{
    return str_replace(
        ["\\", "\r\n", "\r", "\n", ';', ','],
        ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
        $value,
    );
}

/** Fold a content line at 75 octets without splitting a UTF-8 character. */
function foldIcalLine(string $line): string
{
    $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false) {
        throw new RuntimeException('ICSに変換できないUTF-8文字列が含まれています');
    }

    $lines = [];
    $current = '';
    $limit = 75;

    foreach ($characters as $character) {
        if ($current !== '' && strlen($current . $character) > $limit) {
            $lines[] = $current;
            $current = ' ';
            $limit = 75;
        }
        $current .= $character;
    }
    $lines[] = $current;

    return implode("\r\n", $lines);
}

function requireString(array $item, string $key): string
{
    $value = $item[$key] ?? null;
    if (!is_string($value) || $value === '') {
        throw new UnexpectedValueException("timeslotの{$key}が不正です");
    }
    return $value;
}

function fetchTimetable(): array
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 20,
            'user_agent' => 'rela1470-public timetable updater',
            'ignore_errors' => true,
        ],
    ]);
    $json = @file_get_contents(TIMETABLE_URL, false, $context);
    if ($json === false) {
        throw new RuntimeException('タイムテーブルAPIの取得に失敗しました');
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException("タイムテーブルAPIがエラーを返しました: {$statusLine}");
    }

    $response = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($response) || !isset($response['timetable']) || !is_array($response['timetable'])) {
        throw new UnexpectedValueException('APIレスポンスにtimetable配列がありません');
    }
    return $response['timetable'];
}

function buildCalendar(array $timetable): array
{
    $events = array_values(array_filter(
        $timetable,
        static fn (mixed $item): bool => is_array($item)
            && ($item['type'] ?? null) === 'timeslot'
            && ($item['track']['name'] ?? null) === TARGET_TRACK,
    ));

    usort($events, static function (array $left, array $right): int {
        return [($left['starts_at'] ?? ''), ($left['uuid'] ?? '')]
            <=> [($right['starts_at'] ?? ''), ($right['uuid'] ?? '')];
    });

    if ($events === []) {
        throw new RuntimeException('対象のアンカンファレンスtimeslotが見つかりませんでした');
    }

    $utc = new DateTimeZone('UTC');
    $stamp = new DateTimeImmutable('now', $utc);
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//iOSDC Japan 2026//Unconference//JA',
        'CALSCALE:GREGORIAN',
        'X-WR-CALNAME:' . escapeIcalText('iOSDC Japan 2026 アンカンファレンス'),
        'X-WR-TIMEZONE:Asia/Tokyo',
    ];
    $seenUuids = [];

    foreach ($events as $event) {
        $uuid = requireString($event, 'uuid');
        if (isset($seenUuids[$uuid])) {
            throw new UnexpectedValueException("uuidが重複しています: {$uuid}");
        }
        $seenUuids[$uuid] = true;

        $startsAt = new DateTimeImmutable(requireString($event, 'starts_at'));
        $length = $event['length_min'] ?? null;
        if (!is_int($length) || $length <= 0) {
            throw new UnexpectedValueException("timeslot {$uuid} のlength_minが不正です");
        }
        $endsAt = $startsAt->modify("+{$length} minutes");

        array_push(
            $lines,
            'BEGIN:VEVENT',
            "UID:{$uuid}@fortee.jp",
            'DTSTAMP:' . $stamp->format('Ymd\\THis\\Z'),
            'DTSTART:' . $startsAt->setTimezone($utc)->format('Ymd\\THis\\Z'),
            'DTEND:' . $endsAt->setTimezone($utc)->format('Ymd\\THis\\Z'),
            'SUMMARY:' . escapeIcalText(requireString($event, 'title')),
            'LOCATION:' . escapeIcalText('アンカンファレンス'),
            'END:VEVENT',
        );
    }

    $lines[] = 'END:VCALENDAR';
    return [$events, implode("\r\n", array_map('foldIcalLine', $lines)) . "\r\n"];
}

try {
    [$events, $calendar] = buildCalendar(fetchTimetable());
    $temporaryFile = tempnam(__DIR__, '.unconference.');
    if ($temporaryFile === false) {
        throw new RuntimeException('一時ファイルを作成できませんでした');
    }

    try {
        if (file_put_contents($temporaryFile, $calendar, LOCK_EX) === false) {
            throw new RuntimeException('一時ファイルへ書き込めませんでした');
        }
        if (!rename($temporaryFile, OUTPUT_FILE)) {
            throw new RuntimeException('ICSファイルを置き換えられませんでした');
        }
    } finally {
        if (is_file($temporaryFile)) {
            unlink($temporaryFile);
        }
    }

    fwrite(STDOUT, sprintf("%s を%d件のイベントで更新しました。\n", OUTPUT_FILE, count($events)));
} catch (Throwable $error) {
    fwrite(STDERR, "エラー: {$error->getMessage()}\n");
    exit(1);
}
