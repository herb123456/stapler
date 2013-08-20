<?php namespace Codesleeve\Stapler\Storage;

use Codesleeve\Stapler\File\UploadedFile;
use Aws\S3\S3Client;
use Config;

class S3 implements StorageInterface
{
	/**
	 * The currenty attachedFile object being processed.
	 * 
	 * @var Codesleeve\Stapler\Attachment
	 */
	protected $attachedFile;

	/**
	 * An AWS S3Client instance.
	 * 
	 * @var S3Client
	 */
	protected $s3Client;

	/**
	 * Boolean flag indicating if this attachment's bucket currently exists.
	 * 
	 * @var array
	 */
	protected $bucketExists = false;

	/**
	 * Constructor method
	 * 
	 * @param Codesleeve\Stapler\Attachment $attachedFile
	 */
	function __construct($attachedFile)
	{
		$this->attachedFile = $attachedFile;
		$this->s3Client = S3Client::factory([
			'key' => $attachedFile->key, 
			'secret' => $attachedFile->secret, 
			'region' => $attachedFile->region, 
			'scheme' => $attachedFile->scheme
		]);
	}

	/**
	 * Return the url for a file upload.
	 * 
	 * @param  string $styleName 
	 * @return string          
	 */
	public function url($styleName)
	{
		return $this->s3Client->getObjectUrl($this->getBucket(), $this->path($styleName));
	}

	/**
	 * Return the key the uploaded file object is stored under within a bucket.
	 * 
	 * @param  string $styleName 
	 * @return string          
	 */
	public function path($styleName)
	{
		return $this->attachedFile->getInterpolator()->interpolate($this->attachedFile->path, $this->attachedFile, $styleName);
	}

	/**
	 * Reset an attached file
	 *
	 * @return void
	 */
	public function reset()
	{
		$this->remove();
	}

	/**
	 * Remove an attached file.
	 * 
	 * @param  Codesleeve\Stapler\Attachment $attachedFile
	 * @return void
	 */
	public function remove()
	{
		$this->s3Client->deleteObjects(['Bucket' => $this->getBucket(), 'Objects' => $this->getKeys()]);
	}

	/**
	 * Move an uploaded file to it's intended destination.
	 * The file can be an actual uploaded file object or the path to
	 * a resized image file on disk.
	 *
	 * @param  UploadedFile $file 
	 * @param  string $style
	 * @return void 
	 */
	public function move($file, $style)
	{
		$this->cleanDirectory($style->name);

		$filePath = $this->path($style->name);
 		$file = $file instanceof UploadedFile ? $file->getRealPath() : $file;

 		$this->s3Client->putObject(['Bucket' => $this->getBucket(), 'Key' => $filePath, 'SourceFile' => $file, 'ACL' => $this->attachedFile->ACL]);
	}

	/**
	 * Return an array of paths (bucket keys) for an attachment.
	 * There will be one path for each of the attachmetn's styles.
	 * 	
	 * @return array
	 */
	protected function getKeys()
	{
		$keys = [];

		foreach ($this->attachedFile->styles as $style) {
			$keys[] = ['Key' => $this->path($style->name)];
		}

		return $keys;
	}

	/**
	 * Determine if objects under a style prefix (directory) need to be cleaned (removed).
	 *
	 * @param  string $styleName
	 * @return void
	 */
	protected function cleanDirectory($styleName)
	{
		$filePath = $this->path($styleName);

		if (!$this->attachedFile->keep_old_files) {
			$fileDirectory = dirname($filePath);
			$this->emptyDirectory($fileDirectory);
		}
	}

	/**
	 * Delete all objects in an s3 bucket that match a given prefix (directory).
	 * 
	 * @param  string $prefix
	 * @return void
	 */
	protected function emptyDirectory($prefix)
	{
		$this->s3Client->deleteMatchingObjects($this->getBucket(), $prefix);
	}

	/**
	 * This is a wrapper method for returning the name of an attachment's bucket.
	 * If the bucket doesn't exist we'll build it first before returning it's name.
	 * 
	 * @return string
	 */
	protected function getBucket()
	{
		$bucketName = $this->attachedFile->bucket;
		if (!$this->bucketExists) {
			$this->buildBucket($bucketName);
		}

		return $bucketName;
	}

	/**
	 * Attempt to build a bucket (if it doesn't already exist).
	 * 
	 * @param  string $bucketName
	 * @return void
	 */
	public function buildBucket($bucketName)
	{
		if (!$this->s3Client->doesBucketExist($bucketName, true)) {
			$this->s3Client->createBucket(['ACL' => $this->attachedFile->ACL, 'Bucket' => $bucketName, 'LocationConstraint' => $this->attachedFile->region]);
		}

		$this->bucketExists = true;
	}
}