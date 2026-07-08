<?php


namespace App\Helpers;

use Illuminate\Support\Facades\Storage;

class FileHelper
{

    public function move_temporary_files_to_official_folder($fileNames, $temporaryFolder, $officialFolder)
    {
        $newFileNames = [];
        foreach ($fileNames as $key => $fileName) {

            $newFileName = $fileName;
            while (Storage::disk('public')->exists($officialFolder.'/'.$newFileName)) {
                $newFileName = $this->make_unique_file_name($officialFolder, $fileName);
            }

            $temporaryFile = 'temporary/'.$temporaryFolder.'/'.$fileName;
            $officialFile = $officialFolder.'/'.$newFileName;

            Storage::disk('public')->move($temporaryFile, $officialFile);

            $newFileNames[$key] = $newFileName;
        }

        return $newFileNames;
    }

    public function move_to_folder($file, $folderPath)
    {
        return $this->save_to_public_storage($file, $folderPath);
    }

    public function save_to_public_storage($file, $folderPath, $fileName = null)
    {
        $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
        $storageFolder = 'storage/'.$folderPath;
        $directory = public_path($storageFolder);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $fileName = $fileName ?? $this->sanitize_file_name($file->getClientOriginalName());
        $fullPath = $directory.DIRECTORY_SEPARATOR.$fileName;

        if (file_exists($fullPath)) {
            $fileName = $this->make_unique_public_file_name($storageFolder, $fileName);
            $fullPath = $directory.DIRECTORY_SEPARATOR.$fileName;
        }

        $file->move($directory, $fileName);

        if (!file_exists($fullPath)) {
            throw new \RuntimeException('Failed to save uploaded file.');
        }

        return [
            'path' => $folderPath.'/'.$fileName,
            'name' => $fileName,
            'url' => $this->normalize_public_storage_url(url($storageFolder.'/'.$fileName)),
        ];
    }
    
    public function move_to_product_file_folder($file, $folderPath)
    {
        $fileName = $file->getClientOriginalName();

        // Get the full path where the file should be saved
        $fullPath = public_path($folderPath . '\\' . $fileName);

        // Check if the file already exists in the specified folder
        if (file_exists($fullPath)) {
            // Generate a unique filename
            $fileName = $this->make_unique_file_name($folderPath, $fileName);

            // Update the full path with the new filename
            $fullPath = public_path($folderPath . '\\' . $fileName);
        }

        // Move the file to the specified path
        $file->move(public_path($folderPath), $fileName);

        // Generate the URL for the file in the public folder
        $url = $folderPath . '/' . $fileName;
        // dd($url);

        return [
            'path' => $folderPath . '/' . $fileName,
            'name' => $fileName,
            'url' => $url,
        ];
    }

    public function sanitize_file_name($fileName, $fallback = 'file')
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $baseName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $baseName);
        $baseName = trim($baseName, '-');

        if ($baseName === '') {
            $baseName = $fallback;
        }

        return $extension !== '' ? $baseName.'.'.$extension : $baseName;
    }

    public function sanitize_banner_file_name($fileName)
    {
        return $this->sanitize_file_name($fileName, 'banner');
    }

    public function normalize_public_storage_url($url)
    {
        $url = preg_replace('#(?<!:)/{2,}#', '/', $url);

        if (!preg_match('#^(https?://[^/]+)(/.*)$#i', $url, $matches)) {
            return $url;
        }

        $segments = explode('/', trim($matches[2], '/'));
        $encodedPath = '/'.implode('/', array_map(function ($segment) {
            return rawurlencode(rawurldecode($segment));
        }, $segments));

        return $matches[1].$encodedPath;
    }

    public function save_banner_file($file, $fileName = null)
    {
        $savedFile = $this->save_to_public_storage($file, 'banners', $fileName);

        return [
            'path' => $savedFile['path'],
            'name' => $savedFile['name'],
            'url' => $savedFile['url'],
        ];
    }

    public function banner_file_exists($relativePath)
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');

        return file_exists(public_path('storage/'.$relativePath))
            || Storage::disk('public')->exists($relativePath);
    }

    public function delete_banner_file($relativePath)
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $publicFile = public_path('storage/'.$relativePath);

        if (file_exists($publicFile)) {
            unlink($publicFile);
        }

        if (Storage::disk('public')->exists($relativePath)) {
            Storage::disk('public')->delete($relativePath);
        }
    }

    private function make_unique_public_file_name($folderPath, $fileName)
    {
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseName = pathinfo($fileName, PATHINFO_FILENAME);
        $count = 2;
        $newFilename = $baseName.'-'.$count.($extension ? '.'.$extension : '');
        $directory = public_path(trim(str_replace('\\', '/', $folderPath), '/'));

        while (file_exists($directory.DIRECTORY_SEPARATOR.$newFilename)) {
            $count += 1;
            $newFilename = $baseName.'-'.$count.($extension ? '.'.$extension : '');
        }

        return $newFilename;
    }

    public function move_to_folder_random_name($folder, $file)
    {
        $fileName = $file->getClientOriginalName();
        $fileNames = explode(".", $fileName);
        $newFilename = time().'.'.$fileNames[1];

        Storage::disk('public')->putFileAs($folder, $file, $newFilename);

        return $newFilename;
    }

    public function upload_file_to_temporary_folder($folderPath, $file)
    {
        $folderPath = 'temporary/'.$folderPath;

        return $this->move_to_folder($file, $folderPath);
    }

    public function delete_file($filePath)
    {
        if ($filePath === null || trim($filePath) === '') {
            return;
        }

        $filePath = ltrim(str_replace('\\', '/', $filePath), '/');
        $publicFile = public_path('storage/'.$filePath);

        if (file_exists($publicFile)) {
            unlink($publicFile);
        }

        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
    }

    public function delete_temporary_folder($folderPath)
    {
        $folderPath = 'temporary/'.$folderPath;
        return Storage::disk('public')->deleteDirectory($folderPath);
    }

    private function make_unique_file_name($folder, $fileName)
    {
        $fileNames = explode(".", $fileName);
        $count = 2;
        $newFilename = $fileNames[0].' ('.$count.').'.$fileNames[1];
        while(Storage::disk('public')->exists($folder.'/'.$newFilename)) {
            $count += 1;
            $newFilename = $fileNames[0].' ('.$count.').'.$fileNames[1];
        }

        return $newFilename;
    }

    private function get_file_name($filePath)
    {
        $filePaths = explode('/', $filePath);
        return $filePath[count($filePaths-1)];
    }
}
