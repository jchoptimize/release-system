/**
 * @package   AkeebaReleaseSystem
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

-- Plain-text CHANGELOG section of the release, served to Joomla as its changelog XML.
ALTER TABLE `#__ars_releases`
    ADD COLUMN `changelog` MEDIUMTEXT NULL AFTER `notes`;
