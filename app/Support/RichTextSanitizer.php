<?php

namespace App\Support;

final class RichTextSanitizer
{
    /**
     * Tags the attendance editor (CKEditor: bold, italic, lists) can produce.
     */
    private const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote'];

    /**
     * Normalize CKEditor HTML before persisting to the database.
     * Keeps only formatting tags without attributes, so the result is safe to render as HTML.
     */
    public static function sanitizeHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        // Entities stay encoded here: decoding would turn typed "&lt;img ...&gt;" into a real tag.
        $html = str_replace("\xc2\xa0", ' ', $html);
        $html = preg_replace('/&nbsp;|&#(?:160|xA0);/iu', ' ', $html) ?? $html;
        $html = self::stripUnsafeMarkup($html);

        $emptyBlockPattern = '/<(p|blockquote|div|span)[^>]*>(?:\s|<br\s*\/?>)*<\/\1>/iu';
        $previous = null;

        while ($previous !== $html) {
            $previous = $html;
            $html = preg_replace($emptyBlockPattern, '', $html) ?? $html;
        }

        $html = trim($html);

        if ($html === '' || self::isEmptyHtml($html)) {
            return null;
        }

        return $html;
    }

    /**
     * Normalize legacy plain-text notes before HTML sanitization.
     */
    public static function sanitizePlainText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = self::replaceNonBreakingSpaces($text);
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    /**
     * Convert stored HTML to readable plain text for tables and summaries.
     */
    public static function toPlainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);

        if ($html === '') {
            return null;
        }

        $html = preg_replace('/<li[^>]*>/iu', '• ', $html) ?? $html;
        $html = preg_replace('/<\/li>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\/p>/iu', "\n", $html) ?? $html;

        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = self::replaceNonBreakingSpaces($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = trim(preg_replace("/\n{2,}/", "\n", $text) ?? $text);

        return $text !== '' ? $text : null;
    }

    private static function stripUnsafeMarkup(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#isu', '', $html) ?? '';
        $html = strip_tags($html, self::ALLOWED_TAGS);

        return preg_replace('#<(/?)([a-z0-9]+)\b[^>]*?(/?)>#iu', '<$1$2$3>', $html) ?? '';
    }

    private static function replaceNonBreakingSpaces(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace("\xc2\xa0", ' ', $value);

        return preg_replace('/&nbsp;|&#(?:160|xA0);/iu', ' ', $value) ?? $value;
    }

    private static function isEmptyHtml(string $html): bool
    {
        $text = preg_replace('/<[^>]*>/', '', $html) ?? '';
        $text = self::replaceNonBreakingSpaces($text);
        $text = preg_replace('/\s+/u', '', $text) ?? $text;

        return $text === '';
    }
}
