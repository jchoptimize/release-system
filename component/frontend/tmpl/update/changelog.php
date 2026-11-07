<?php
/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

defined('_JEXEC') or die();

/**
 * Joomla changelog XML of an update stream. The XML update stream's <changelogurl> points here.
 *
 * @see https://manual.joomla.org/docs/building-extensions/install-update/installation/changelog/
 */

/** @var \Akeeba\Component\ARS\Site\View\Update\XmlView $this */

echo $this->buildChangelogXml($this->items ?? []);
