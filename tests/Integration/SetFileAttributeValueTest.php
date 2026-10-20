<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tests\Integration;

use Concrete\Core\Attribute\Key\FileKey;
use Concrete\Core\Database\Connection\Connection;
use Concrete\Core\File\File;
use Concrete\Core\Http\Request;
use Concrete\Core\User\UserInfoRepository;
use Katalysis\NeuronAi\Tests\Support\ConcreteTestCase;
use Katalysis\NeuronAi\Tools\FileAttributesToolkit;

/**
 * P1.13: set_file_attribute_value stays enabled, but a file has no draft, so the write is live. The result therefore
 * reports the previous value, so the reply can say how to undo it (B-WRT-2). The test uses an existing file and the
 * existing "duration" number attribute, which no image uses, and clears it in tearDown() so nothing is left behind.
 */
final class SetFileAttributeValueTest extends ConcreteTestCase
{
    private const HANDLE = 'duration';

    private int $fileID = 0;

    private int $highestAttributeValueID = 0;

    protected function setUp(): void
    {
        $repository = $this->app()->make(UserInfoRepository::class);
        Request::getInstance()->setCustomRequestUser($repository->getByID(USER_SUPER_ID));

        $ak = FileKey::getByHandle(self::HANDLE);
        if (!$ak) {
            $this->markTestSkipped('The "' . self::HANDLE . '" file attribute does not exist on this site.');
        }
        $this->fileID = $this->firstImageFileID();
        if ($this->fileID === 0) {
            $this->markTestSkipped('The site has no file to use.');
        }
        $this->assertTrue(
            File::getByID($this->fileID)->getVersion()->getAttribute(self::HANDLE) === null,
            'The test needs a file with no duration value, so it can restore the original state.'
        );
        $this->highestAttributeValueID = (int) $this->db()->fetchOne('SELECT MAX(avID) FROM AttributeValues');
    }

    protected function tearDown(): void
    {
        if ($this->fileID) {
            $ak = FileKey::getByHandle(self::HANDLE);
            File::getByID($this->fileID)->getVersion()->clearAttribute($ak);
            // clearAttribute() unlinks the value from the file but leaves its AttributeValues row behind. The
            // atNumber row goes with it (ON DELETE CASCADE).
            $this->db()->executeStatement(
                'DELETE FROM AttributeValues WHERE akID = ? AND avID > ?',
                [(int) $ak->getAttributeKeyID(), $this->highestAttributeValueID]
            );
            // The raw delete leaves Doctrine's identity map and the file cache pointing at the removed rows.
            $this->app()->make(\Doctrine\ORM\EntityManagerInterface::class)->clear();
            $this->app()->make('cache/request')->flush();
        }
        Request::getInstance()->setCustomRequestUser(-1);
    }

    private function db(): Connection
    {
        return $this->app()->make(Connection::class);
    }

    private function firstImageFileID(): int
    {
        foreach ([6, 7, 8, 9, 2, 3] as $fID) {
            $file = File::getByID($fID);
            if ($file && !$file->isError() && $file->getVersion()->getAttribute(self::HANDLE) === null) {
                return $fID;
            }
        }

        return 0;
    }

    private function setValue(string $value): array
    {
        foreach ((new FileAttributesToolkit())->tools() as $tool) {
            if ($tool->getName() === 'set_file_attribute_value') {
                $tool = clone $tool;
                $tool->setInputs(['fileID' => $this->fileID, 'handle' => self::HANDLE, 'value' => $value])->execute();
                $decoded = json_decode((string) $tool->getResult(), true);
                $this->assertIsArray($decoded, 'The tool result is not JSON: ' . $tool->getResult());

                return $decoded;
            }
        }
        $this->fail('set_file_attribute_value is not in the toolkit');
    }

    public function testTheDescriptionSaysTheChangeIsLiveAndReportsThePreviousValue(): void
    {
        foreach ((new FileAttributesToolkit())->tools() as $tool) {
            if ($tool->getName() === 'set_file_attribute_value') {
                $this->assertStringContainsString('live', $tool->getDescription());
                $this->assertStringContainsString('previousValue', $tool->getDescription());

                return;
            }
        }
        $this->fail('set_file_attribute_value is not in the toolkit');
    }

    public function testTheFirstSetReportsThatThereWasNoPreviousValue(): void
    {
        $result = $this->setValue('7');

        $this->assertTrue($result['success']);
        $this->assertSame('7', $result['attribute']['valueSet']);
        $this->assertNull($result['attribute']['previousValue']);
        $this->assertStringContainsString('previous value was empty', $result['message']);
        $this->assertStringContainsString('File Manager', $result['message']);
    }

    public function testASecondSetReportsTheValueItReplacedAndHowToUndoIt(): void
    {
        $this->setValue('7');
        $result = $this->setValue('9');

        $this->assertSame('9', $result['attribute']['valueSet']);
        $this->assertSame('7', $result['attribute']['previousValue']);
        $this->assertStringContainsString("previous value was '7'", $result['message']);
        $this->assertStringContainsString("set it back to '7'", $result['message']);
    }

    public function testSettingThePreviousValueBackRestoresIt(): void
    {
        $this->setValue('7');
        $this->setValue('9');
        $undo = $this->setValue('7');

        $this->assertSame('9', $undo['attribute']['previousValue']);
        $this->assertSame('7', $undo['attribute']['valueSet']);
    }
}
