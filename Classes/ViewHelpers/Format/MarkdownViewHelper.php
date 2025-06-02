<?php

declare(strict_types=1);

namespace IGelb\Aigelb\ViewHelpers\Format;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithRenderStatic;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\Traits\CompileWithContentArgumentAndRenderStatic;

/**
 * Markdown to HTML ViewHelper for AI-Gelb Extension
 *
 * Converts Markdown text to HTML for proper display in templates.
 * Handles AI responses that may come as single-line text without proper line breaks.
 *
 * = Examples =
 *
 * <code title="Basic usage">
 * <aigelb:format.markdown>{aiResponse}</aigelb:format.markdown>
 * </code>
 *
 * <code title="Inline usage">
 * {aiResponse -> aigelb:format.markdown()}
 * </code>
 */
final class MarkdownViewHelper extends AbstractViewHelper
{
    use CompileWithContentArgumentAndRenderStatic;

    /**
     * Disable escaping as we're outputting HTML
     */
    protected $escapeOutput = false;

    /**
     * Initialize arguments for the ViewHelper
     */
    public function initializeArguments(): void
    {
        $this->registerArgument('value', 'string', 'The Markdown text to convert');
    }

    /**
     * Render the Markdown conversion
     *
     * @param array $arguments ViewHelper arguments
     * @param \Closure $renderChildrenClosure Closure to render children
     * @param RenderingContextInterface $renderingContext Rendering context
     * @return string Converted HTML
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ): string {
        $markdown = $arguments['value'] ?? $renderChildrenClosure();

        if (empty($markdown)) {
            return '';
        }

        return self::convertMarkdownToHtml($markdown);
    }

    /**
     * Convert Markdown text to HTML with AI response preprocessing
     *
     * @param string $markdown Markdown text
     * @return string HTML output
     */
    private static function convertMarkdownToHtml(string $markdown): string
    {
        // First, preprocess AI responses that come as single-line text
        $html = self::preprocessAIResponse($markdown);

        // Normalize line endings
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Headers (# ## ### #### ##### ######)
        $html = preg_replace('/^###### (.*$)/m', '<h6>$1</h6>', $html);
        $html = preg_replace('/^##### (.*$)/m', '<h5>$1</h5>', $html);
        $html = preg_replace('/^#### (.*$)/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^### (.*$)/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^## (.*$)/m', '<h2>$1</h2>', $html);
        $html = preg_replace('/^# (.*$)/m', '<h1>$1</h1>', $html);

        // Bold text (**text** or __text__)
        $html = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/__(.*?)__/', '<strong>$1</strong>', $html);

        // Italic text (*text* or _text_) - but not if it's part of a list
        $html = preg_replace('/(?<!\s[\*\-])\s\*(.*?)\*(?!\s)/', ' <em>$1</em>', $html);
        $html = preg_replace('/(?<!\s[\*\-])\s_(.*?)_(?!\s)/', ' <em>$1</em>', $html);

        // Code inline (`code`)
        $html = preg_replace('/`(.*?)`/', '<code>$1</code>', $html);

        // Code blocks (```code```)
        $html = preg_replace('/```(.*?)```/s', '<pre><code>$1</code></pre>', $html);

        // Links [text](url)
        $html = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $html);

        // Process lists
        $html = self::processLists($html);

        // Blockquotes (> text)
        $html = preg_replace('/^>\s*(.+)$/m', '<blockquote>$1</blockquote>', $html);

        // Horizontal rules (--- or ***)
        $html = preg_replace('/^(---|\*\*\*)$/m', '<hr>', $html);

        // Process paragraphs
        $html = self::processParagraphs($html);

        return $html;
    }

    /**
     * Preprocess AI responses to add proper line breaks
     *
     * @param string $text Raw AI response text
     * @return string Preprocessed text with proper line breaks
     */
    private static function preprocessAIResponse(string $text): string
    {
        // Add line breaks before numbered list items (1. 2. 3. etc.)
        $text = preg_replace('/(\d+\.\s\*\*[^*]+\*\*:?)/', "\n$1", $text);

        // Add line breaks before bullet points that start with dash
        $text = preg_replace('/(\s-\s[^-]+)/', "\n$1", $text);

        // Add line breaks after sentences that end with periods followed by numbers
        $text = preg_replace('/(\.\s)(\d+\.\s)/', "$1\n$2", $text);

        // Add line breaks after colons that are followed by bullet points
        $text = preg_replace('/(:)\s(-\s)/', "$1\n$2", $text);

        // Clean up multiple consecutive line breaks
        $text = preg_replace('/\n\s*\n\s*\n/', "\n\n", $text);

        // Trim leading/trailing whitespace
        return trim($text);
    }

    /**
     * Process lists with support for nested lists and AI-style formatting
     *
     * @param string $html HTML content
     * @return string Processed HTML with proper list tags
     */
    private static function processLists(string $html): string
    {
        $lines = explode("\n", $html);
        $result = [];
        $listStack = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // Check for ordered list (1. 2. 3. etc.) - including bold titles
            if (preg_match('/^(\d+)\.\s+(.*)$/', $trimmedLine, $matches)) {
                if (!$inList || end($listStack) !== 'ol') {
                    // Close any open nested lists first
                    while (!empty($listStack) && end($listStack) === 'ul-nested') {
                        $result[] = '</ul>';
                        array_pop($listStack);
                    }

                    if (!$inList) {
                        $result[] = '<ol>';
                        $listStack[] = 'ol';
                        $inList = true;
                    }
                }
                $result[] = '<li>' . $matches[2] . '</li>';
            }
            // Check for unordered list items starting with dash (- item)
            elseif (preg_match('/^-\s+(.*)$/', $trimmedLine, $matches)) {
                // This is a nested list item
                if (!$inList || end($listStack) !== 'ul-nested') {
                    $result[] = '<ul>';
                    $listStack[] = 'ul-nested';
                }
                $result[] = '<li>' . $matches[1] . '</li>';
            }
            // Check for unordered list (* item)
            elseif (preg_match('/^\*\s+(.*)$/', $trimmedLine, $matches)) {
                if (!$inList || (end($listStack) !== 'ul' && end($listStack) !== 'ul-nested')) {
                    if ($inList && end($listStack) === 'ol') {
                        // Start nested ul within ol
                        $result[] = '<ul>';
                        $listStack[] = 'ul-nested';
                    } else {
                        if ($inList) {
                            while (!empty($listStack)) {
                                $result[] = self::closeListTag(array_pop($listStack));
                            }
                        }
                        $result[] = '<ul>';
                        $listStack[] = 'ul';
                        $inList = true;
                    }
                }
                $result[] = '<li>' . $matches[1] . '</li>';
            }
            // Not a list item
            else {
                // Close nested lists only if we hit a non-list line and it's not empty
                if ($inList && !empty($trimmedLine) && end($listStack) === 'ul-nested') {
                    $result[] = '</ul>';
                    array_pop($listStack);
                }
                // Close all lists if we hit a significant non-list line
                elseif ($inList && !empty($trimmedLine) && !preg_match('/^\s*$/', $trimmedLine)) {
                    // Check if this might be a continuation of a list item
                    $prevLine = end($result);
                    if (!preg_match('/<li>.*<\/li>$/', $prevLine)) {
                        while (!empty($listStack)) {
                            $result[] = self::closeListTag(array_pop($listStack));
                        }
                        $inList = false;
                    }
                }

                // Add the line if it's not empty or if we're not in a list context
                if (!empty($trimmedLine) || !$inList) {
                    $result[] = $line;
                }
            }
        }

        // Close any remaining open lists
        while (!empty($listStack)) {
            $result[] = self::closeListTag(array_pop($listStack));
        }

        return implode("\n", $result);
    }

    /**
     * Close appropriate list tag
     *
     * @param string $listType Type of list to close
     * @return string Closing tag
     */
    private static function closeListTag(string $listType): string
    {
        if ($listType === 'ol') {
            return '</ol>';
        } elseif ($listType === 'ul' || $listType === 'ul-nested') {
            return '</ul>';
        }
        return '';
    }

    /**
     * Process paragraphs while preserving list structure
     *
     * @param string $html HTML content
     * @return string Processed HTML with proper paragraphs
     */
    private static function processParagraphs(string $html): string
    {
        // Split by double newlines but preserve list structure
        $sections = preg_split('/\n\s*\n/', $html);
        $result = [];

        foreach ($sections as $section) {
            $section = trim($section);
            if (empty($section)) {
                continue;
            }

            // Check if this section contains HTML tags (lists, headers, etc.)
            if (preg_match('/<(h[1-6]|ul|ol|pre|blockquote|li)/', $section)) {
                $result[] = $section;
            } else {
                // Regular paragraph - but check if it's really paragraph content
                if (strlen($section) > 10) { // Only create paragraphs for substantial content
                    $result[] = '<p>' . $section . '</p>';
                } else {
                    $result[] = $section;
                }
            }
        }

        return implode("\n\n", $result);
    }
}
