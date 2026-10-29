<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Approval\Preview;

use Concrete\Core\Attribute\Key\FileKey;
use Concrete\Core\File\File;
use Concrete\Core\File\FileList;
use Concrete\Core\File\Filesystem;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Katalysis\NeuronAi\Tools\FilesToolkit;

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
            $fID = PreviewSupport::int($inputs, 'fileID');
            $name = PreviewSupport::string($inputs, 'filename');

            return [
                PreviewSupport::entry('Set ' . $label . ' on', 'a file that was not found', '', [PreviewSupport::change($label, null, $value)], PreviewSupport::LIVE_NOTE),
                ['missing' => [$inputs['fileID'] ?? null, $inputs['filename'] ?? null]],
                // P3.6-2: set_file_attribute_value's own words (CallCheck). A name matching several files is left to it.
                $fID > 0
                    ? "File {$fID} not found: it may have been deleted, or the ID is wrong. Find it with list_files. Nothing was changed."
                    : ($name !== '' && self::filesNamed($name) === 0 ? "No file is named '{$name}'. Find it with list_files and send its fileID. Nothing was changed." : null),
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
            $handle !== '' && $key === null
                ? "File attribute '{$handle}' not found: there is no attribute with that handle. list_file_attributes lists the handles. Nothing was changed."
                : null,
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
            $parentID > 0 && !$parent instanceof FileFolder
                ? "Folder {$parentID} not found: it may have been deleted, or the ID is wrong. list_file_folders lists the folders and their IDs. Nothing was changed."
                : null,
        ];
    }

    /** P3.6-3: mark_file_for_deletion, the delete redirect (B-T1-11). */
    public static function markForDeletion(array $inputs): array
    {
        $file = self::file(['fileID' => is_numeric($inputs['fileID'] ?? null) ? (int) $inputs['fileID'] : 0]);
        $review = FilesToolkit::reviewFolder();
        $note = 'Nothing is deleted: a person reviews and deletes it there. Anyone who can see that folder can see the file.'
            . ($review === null ? ' The folder is created first.' : '');
        if ($file === null) {
            return [
                PreviewSupport::entry('Move a file to "' . FilesToolkit::REVIEW_FOLDER . '"', 'a file that was not found', '', [], $note),
                ['missing' => $inputs['fileID'] ?? null],
            ];
        }

        $version = $file->getApprovedVersion();
        $folder = $file->getFileFolderObject();

        return [
            PreviewSupport::entry(
                'Move the file',
                (string) ($version ? $version->getFileName() : 'file ' . $file->getFileID()) . ' to "' . FilesToolkit::REVIEW_FOLDER . '", for a person to review and delete',
                '',
                ['From: ' . ($folder ? $folder->getTreeNodeDisplayPath() : '(no folder)')],
                $note
            ),
            [
                'fID' => (int) $file->getFileID(),
                'folder' => $folder ? (int) $folder->getTreeNodeID() : 0,
                'review' => $review ? (int) $review->getTreeNodeID() : 0,
            ],
        ];
    }

    private static function filesNamed(string $name): int
    {
        $list = new FileList();
        $list->filterByFilename($name);

        return count($list->getResults());
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
