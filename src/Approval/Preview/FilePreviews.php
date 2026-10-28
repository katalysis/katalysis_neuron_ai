<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Attribute\Key\FileKey;
use Concrete\Core\File\File;
use Concrete\Core\File\FileList;
use Concrete\Core\File\Filesystem;
use Concrete\Core\Tree\Node\Type\FileFolder;

/**
 * The approval cards of the file writes (P3.6-2). Files have no draft, so the cards say the change takes effect at once.
 * Reads only.
 */
final class FilePreviews
{
    public static function setAttribute(array $inputs): array
    {
        $file = self::file($inputs);
        $handle = PreviewSupport::string($inputs, 'handle');
        $key = $handle !== '' ? FileKey::getByHandle($handle) : null;
        $label = $key ? (string) $key->getAttributeKeyName() : $handle;
        $value = PreviewSupport::text($inputs['value'] ?? '');
        if ($file === null) {
            return [
                PreviewSupport::entry('Set ' . $label . ' on', 'a file that was not found', '', [PreviewSupport::change($label, null, $value)], PreviewSupport::LIVE_NOTE),
                ['missing' => [$inputs['fileID'] ?? null, $inputs['filename'] ?? null]],
            ];
        }

        $version = $file->getApprovedVersion();
        $current = $version && $key ? PreviewSupport::text($version->getAttribute($key)) : '';

        return [
            PreviewSupport::entry(
                'Set ' . $label . ' on the file',
                (string) ($version ? $version->getFileName() : 'file ' . $file->getFileID()),
                '',
                [PreviewSupport::change($label, $current, $value)],
                'Files have no draft: this is live at once.'
            ),
            ['fID' => (int) $file->getFileID(), 'fvID' => $version ? (int) $version->getFileVersionID() : 0, 'handle' => $handle, 'value' => $current],
        ];
    }

    public static function createFolder(array $inputs): array
    {
        $name = PreviewSupport::string($inputs, 'name');
        $parentID = PreviewSupport::int($inputs, 'parentFolderID');
        $parent = $parentID > 0 ? FileFolder::getByID($parentID) : (new Filesystem())->getRootFolder();
        $parentName = $parent instanceof FileFolder ? (string) $parent->getTreeNodeDisplayPath() : 'a folder that was not found';
        $children = [];
        if ($parent instanceof FileFolder) {
            $parent->populateDirectChildrenOnly();
            foreach ($parent->getChildNodes() as $child) {
                $children[] = (string) $child->getTreeNodeName();
            }
            sort($children);
        }
        $details = ($inputs['createParents'] ?? false) === true ? ['Missing folders on the way are created too.'] : [];

        return [
            PreviewSupport::entry('Create the folder', '"' . $name . '" under ' . $parentName, '', $details, PreviewSupport::LIVE_NOTE),
            ['parent' => $parent instanceof FileFolder ? (int) $parent->getTreeNodeID() : 0, 'children' => $children],
        ];
    }

    private static function file(array $inputs): ?\Concrete\Core\Entity\File\File
    {
        $fID = PreviewSupport::int($inputs, 'fileID');
        if ($fID > 0) {
            $file = File::getByID($fID);

            return $file instanceof \Concrete\Core\Entity\File\File ? $file : null;
        }
        $name = PreviewSupport::string($inputs, 'filename');
        if ($name === '') {
            return null;
        }
        $list = new FileList();
        $list->filterByFilename($name);
        $results = $list->getResults();

        return count($results) === 1 ? $results[0] : null;
    }
}
