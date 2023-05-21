<?php

namespace App\Models;

use App\Services\Ai\AiService;
use App\Services\Ai\Mappers\StripAiMapper;
use App\Services\Converters\ConverterResolver;
use App\Services\Storage\Adapters\EmbeddedStepService;
use App\Services\Storage\Adapters\UploadedStepService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryFile extends Model
{
    use HasFactory;

    public const STATUS_CHUNKED_OK = 1;
    public const STATUS_CHUNKED_ERROR = 2;
    public const STATUS_CHUNKED_PROCESSING = 3;

    const DISK_CORE_PATH = 'libraries';
    const DISK_SUB_PATH_RAW = 'raw';
    const DISK_SUB_PATH_DB = 'db';

    protected $fillable = ['library_id', 'original_name', 'file_key', 'filename'];

    protected $casts = [
        'chunked_list' => 'array'
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class, 'library_id');
    }

    private function getPathForSavingRawFile(): string
    {
        return self::DISK_CORE_PATH
            . '/' . self::DISK_SUB_PATH_RAW
            . '/' . round($this->library_id/10000, 0)
            . '/' . round($this->library_id/1000, 0)
            . '/' . $this->filename;
    }

    public function saveRawFile(string $content)
    {
        $storage = new UploadedStepService();
        $filePath = $this->getPathForSavingRawFile();
        $storage->upload($filePath, $content);
        if ($storage->exists($filePath)) {
            $this->uploaded = true;
            $this->save();
        }
    }

    public function downloadRawFile(): mixed
    {
        $uploadedService = new UploadedStepService();
        return $uploadedService->download($this->getPathForSavingRawFile(), $this->original_name);
    }

    public function deleteRawFile()
    {
        $storage = new UploadedStepService();
        $filePath = $this->getPathForSavingRawFile();
        $storage->delete($filePath);
    }

    public function saveConvertedFile()
    {
        $rawFilePath = $this->getPathForSavingRawFile();
        $convertedFilePath = $this->getPathToSavingConvertedFile();
        ConverterResolver::make($rawFilePath)->resolve()->handle($convertedFilePath);
        $storage = new UploadedStepService();
        if ($storage->exists($convertedFilePath)) {
            $this->formatted = true;
            $this->save();
        }
    }

    public function deleteConvertedFile()
    {
        $storage = new UploadedStepService();
        $filePath = $this->getPathToSavingConvertedFile();
        $storage->delete($filePath);
    }

    public function getConvertedFile()
    {
        $storage = new UploadedStepService();
        $convertedFilePath = $this->getPathToSavingConvertedFile();

        return $storage->get($convertedFilePath);
    }

    private function _getFileType() : string
    {
        $fileType = explode('.', $this->original_name);
        return strtolower(end($fileType));
    }

    private function splitDataIntoChunks($fileData)
    {
        $chunks = [];

        // CSV file with "chunk_separator"
        if ($this->_getFileType() == 'csv') {
            return $chunks = explode(PHP_EOL, $fileData);
        }

        // file with "chunk_separator"
        if (!empty($this->library->chunk_separator)) {
            return $chunks = explode($this->library->chunk_separator, $fileData);
        }

        // simple file with "chunk_size"
        $parts = explode("\n", $fileData);
        $k = 0;
        foreach ($parts as $part) {
            if (!empty($part)) {
                if (!empty($chunks[$k])) {
                    $chunks[$k] .= "\n";
                }
                if (empty($chunks[$k])) {
                    $chunks[$k] = $part;
                } else {
                    $chunks[$k] .= $part;
                }
                if (strlen($chunks[$k]) > $this->library->chunk_size) {
                    $k++;
                }
            }
        }

        return $chunks;
    }

    public function saveEmbeddedFile()
    {
        $rawStorage = new UploadedStepService();
        $embeddedStorage = new EmbeddedStepService();
        $embeddedFilePath = $this->getPathToSavingEmbeddedFile();
        $convertedFilePath = $this->getPathToSavingConvertedFile();

        $chunks = $this->splitDataIntoChunks($rawStorage->get($convertedFilePath));
        if (!empty($chunks)) {
            if (!$embeddedStorage->exists($embeddedFilePath)) {
                $embeddedStorage->upload($embeddedFilePath, '');
            }
            $client = AiService::createEmbeddingFactory();
            $html = $texts = $vectors = [];
            foreach ($chunks as $key => $chunk) {
                $texts[$key] = StripAiMapper::make($chunk)->handle()->getResult();
                $html[$key] = $chunk;
                try {
                    $response = $client->send($texts[$key], $this->library->embedded_model);
                    if (!empty($response)) {
                        $vectors[$key] = $response;
                    }
                } catch (\Exception $e) {
                    $vectors[$key] = [];
                }
            }
            if (!empty($texts) && !empty($vectors)) {
                $embeddedStorage->upload($embeddedFilePath, json_encode([
                    'html' => $html,
                    'texts' => $texts,
                    'vectors' => $vectors,
                    // 'meta' => $meta TODO
                ]));
            }
        }

        if ($embeddedStorage->exists($convertedFilePath)) {
            $this->embedded = true;
            $this->save();
        }
    }

    public function deleteEmbeddedFile()
    {
        $storage = new EmbeddedStepService();
        $filePath = $this->getPathToSavingEmbeddedFile();
        $storage->delete($filePath);
    }

    private function getPathToSavingConvertedFile() : string
    {
        return self::DISK_CORE_PATH
            . '/'
            .  self::DISK_SUB_PATH_DB
            . '/' . round($this->library_id/10000, 0)
            . '/' . round($this->library_id/1000, 0)
            . '/'
            . $this->id
            . '.txt';
    }

    public function getPathToSavingEmbeddedFile() : string
    {
        return self::DISK_CORE_PATH
            . '/'
            .  self::DISK_SUB_PATH_DB
            . '/' . round($this->library_id/10000, 0)
            . '/' . round($this->library_id/1000, 0)
            . '/'
            . $this->id
            . '.embd';
    }
}
