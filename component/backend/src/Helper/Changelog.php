<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Component\ARS\Administrator\Helper;

defined('_JEXEC') || die;

/**
 * Reads the plain-text CHANGELOG format used across our repositories.
 *
 * A CHANGELOG lists versions newest first. Each version is a heading underlined with '=' characters, followed by
 * one entry per line. An entry starts with a glyph naming its kind, then whitespace, then the text:
 *
 *     MyExtension 1.2.3
 *     ================================================================================
 *     ! SECURITY: Fixed an information disclosure
 *     + Added a thing
 *     # [LOW] Fixed a thing
 *
 * The glyphs map one-to-one onto the seven groups of Joomla's changelog XML format.
 *
 * @since  7.5.2
 */
final class Changelog
{
	/**
	 * Joomla's changelog groups, in the order ARS emits them. Security comes first, so it's what admins see first.
	 *
	 * @since  7.5.2
	 */
	public const GROUPS = ['security', 'fix', 'language', 'addition', 'change', 'remove', 'note'];

	/**
	 * Entry glyph => Joomla changelog group.
	 *
	 * @since  7.5.2
	 */
	public const GLYPHS = [
		'!' => 'security',
		'#' => 'fix',
		'$' => 'language',
		'+' => 'addition',
		'~' => 'change',
		'-' => 'remove',
		'^' => 'note',
	];

	/**
	 * Group the entries of one version's changelog by Joomla changelog group.
	 *
	 * A non-empty line that doesn't start with a known glyph followed by whitespace is kept as a note, with its
	 * text unchanged. Dropping it silently would lose information an admin may need.
	 *
	 * @param   string|null  $section  The entry lines of a single version, without the heading.
	 *
	 * @return  array<string,string[]>  Group => entry texts. Only non-empty groups, in {@see self::GROUPS} order.
	 * @since   7.5.2
	 */
	public static function parse(?string $section): array
	{
		$grouped = array_fill_keys(self::GROUPS, []);

		foreach (self::lines($section ?? '') as $line)
		{
			$line = trim($line);

			if ($line === '')
			{
				continue;
			}

			$group = 'note';
			$text  = $line;

			if (preg_match('/^(\S)\s+(.+)$/u', $line, $matches) && isset(self::GLYPHS[$matches[1]]))
			{
				$group = self::GLYPHS[$matches[1]];
				$text  = trim($matches[2]);
			}

			$text = self::cleanText($text);

			if ($text !== '')
			{
				$grouped[$group][] = $text;
			}
		}

		return array_filter($grouped);
	}

	/**
	 * Return the topmost (newest) version section of a whole CHANGELOG file.
	 *
	 * A heading is a non-empty line immediately followed by a line of at least three '=' characters. A
	 * `<?php die(); ?>` line at the top, which our CHANGELOG files carry, is not a heading and is skipped.
	 *
	 * @param   string  $contents  The contents of the CHANGELOG file.
	 *
	 * @return  array{heading: string, body: string}|null  Null when the file has no recognisable section.
	 * @since   7.5.2
	 */
	public static function latestSection(string $contents): ?array
	{
		$lines   = self::lines($contents);
		$count   = count($lines);
		$heading = null;
		$body    = [];

		for ($i = 0; $i < $count; $i++)
		{
			$isHeading = trim($lines[$i]) !== ''
				&& ($i + 1 < $count)
				&& preg_match('/^={3,}\s*$/', $lines[$i + 1]);

			if ($isHeading)
			{
				// The next heading ends the newest section.
				if ($heading !== null)
				{
					break;
				}

				$heading = trim($lines[$i]);
				$i++;

				continue;
			}

			if ($heading !== null)
			{
				$body[] = rtrim($lines[$i]);
			}
		}

		if ($heading === null)
		{
			return null;
		}

		return [
			'heading' => $heading,
			'body'    => trim(implode("\n", $body), "\n"),
		];
	}

	/**
	 * Split text into lines, whatever its line endings.
	 *
	 * @param   string  $text
	 *
	 * @return  string[]
	 * @since   7.5.2
	 */
	private static function lines(string $text): array
	{
		return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
	}

	/**
	 * Make an entry's text safe to put in an XML document.
	 *
	 * Drops invalid UTF-8 and the C0 control characters XML 1.0 forbids. Either would make the whole changelog
	 * unparseable, and Joomla would show an empty modal.
	 *
	 * @param   string  $text
	 *
	 * @return  string
	 * @since   7.5.2
	 */
	private static function cleanText(string $text): string
	{
		if (!mb_check_encoding($text, 'UTF-8'))
		{
			$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
		}

		return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? '');
	}
}
