<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Traits\TaxaQualityAssessmentTrait;
use App\Models\TaxaQualityAssessment;
use App\Models\FobiChecklistTaxa;
use Intervention\Image\ImageManagerStatic as Image;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class FobiGeneralObservationController extends Controller
{
    use TaxaQualityAssessmentTrait;

    private function getLocationName($latitude, $longitude)
    {
        try {
            if (!$latitude || !$longitude) {
                return 'Unknown Location';
            }

        $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$latitude}&lon={$longitude}&addressdetails=1";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'FOBi Application');

            $response = curl_exec($ch);
            $data = json_decode($response, true);

        if (isset($data['address'])) {
            $address = $data['address'];
            $parts = [];

            // City/Town/Municipality
            if (isset($address['city']) || isset($address['town']) || isset($address['municipality'])) {
                $parts[] = $address['city'] ?? $address['town'] ?? $address['municipality'];
            }

            // County/Regency
            if (isset($address['county']) || isset($address['regency'])) {
                $parts[] = $address['county'] ?? $address['regency'];
            }

            // State (Province)
            if (isset($address['state'])) {
                $parts[] = $address['state'];
            }

            // Country
            if (isset($address['country'])) {
                $parts[] = $address['country'];
            }

            return !empty($parts) ? implode(', ', $parts) : 'Unknown Location';
        }

            return 'Unknown Location';

        } catch (\Exception $e) {
            Log::error('Error getting location name:', [
                'error' => $e->getMessage(),
                'latitude' => $latitude,
                'longitude' => $longitude
            ]);
            return 'Unknown Location';
        }
    }
    public function generateSpectrogram(Request $request)
    {
        try {
            $request->validate([
                'media' => 'required|file|mimes:mp3,wav,ogg,aac,m4a,mp4|max:15120',
            ]);

            $uploadPath = storage_path('app/public/sounds');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            $soundFile = $request->file('media');
            
            // Validasi tambahan untuk ekstensi file
            $originalName = $soundFile->getClientOriginalName();
            $extension = strtolower($soundFile->getClientOriginalExtension());
            $mimeType = $soundFile->getMimeType();
            
            // Cek apakah file adalah audio yang valid
            $isAudioByExt = in_array($extension, ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'mp4']);
            $isAudioByMime = strpos($mimeType, 'audio') !== false;
            
            // Deteksi khusus file WhatsApp
            $isWhatsAppAudio = 
                stripos($originalName, 'whatsapp audio') !== false || 
                stripos($originalName, 'ptt-') !== false || 
                (preg_match('/audio.*\d{4}-\d{2}-\d{2}.*\d{2}\.\d{2}\.\d{2}/i', $originalName) && $extension === 'aac');
                
            // Validasi tipe file
            if (!$isAudioByExt && !$isAudioByMime && !$isWhatsAppAudio) {
                throw new \Exception('Format file tidak didukung. Gunakan file audio dengan format MP3, WAV, AAC, atau M4A.');
            }
            
            // Log informasi file untuk debugging
            Log::info('Spectrogram generation:', [
                'filename' => $originalName,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'size' => $soundFile->getSize(),
                'is_audio_by_extension' => $isAudioByExt,
                'is_audio_by_mime' => $isAudioByMime,
                'is_whatsapp_audio' => $isWhatsAppAudio
            ]);
            
            $soundPath = $soundFile->store('sounds', 'public');
            $spectrogramPath = preg_replace('/\.(mp3|wav|ogg|aac|m4a|mp4)$/i', '.png', $soundPath);

            // Set environment variables
            $env = [
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PYTHONPATH' => '/var/www/talinara/venv/lib/python3.12/site-packages'
            ];

            $command = escapeshellcmd("/var/www/talinara/venv/bin/python " . base_path('/scripts/spectrogram.py') . " " .
                storage_path('app/public/' . $soundPath) . " " .
                storage_path('app/public/' . $spectrogramPath));

            Log::info('Running spectrogram command:', [
                'command' => $command,
                'env' => $env
            ]);

            // Jalankan command dengan environment yang sudah diset
            $process = proc_open($command, [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ], $pipes, null, $env);

            if (is_resource($process)) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                Log::info('Command output:', [
                    'stdout' => $stdout,
                    'stderr' => $stderr
                ]);

                if (!Storage::disk('public')->exists($spectrogramPath)) {
                    throw new \Exception("Gagal membuat spectrogram: $stderr");
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Spectrogram berhasil dibuat',
                    'spectrogramUrl' => asset('storage/' . $spectrogramPath),
                    'audioUrl' => asset('storage/' . $soundPath)
                ]);
            }

            throw new \Exception('Gagal menjalankan proses spectrogram');

        } catch (\Exception $e) {
            Log::error('Error generating spectrogram: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat membuat spectrogram',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function processAudioFile($file)
    {
        try {
            $uploadPath = storage_path('app/public/sounds');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            // Validasi tambahan berdasarkan ekstensi
            $originalName = $file->getClientOriginalName();
            $extension = strtolower($file->getClientOriginalExtension());
            $mimeType = $file->getMimeType();
            
            $isAudioByExt = in_array($extension, ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'mp4', 'opus']);
            $isAudioByMime = strpos($mimeType, 'audio') !== false;
            
            // Deteksi khusus file WhatsApp
            $isWhatsAppAudio = 
                stripos($originalName, 'whatsapp audio') !== false || 
                stripos($originalName, 'ptt-') !== false || 
                (preg_match('/audio.*\d{4}-\d{2}-\d{2}.*\d{2}\.\d{2}\.\d{2}/i', $originalName) && $extension === 'aac');
            
            // Log informasi file audio
            Log::info('Processing audio file:', [
                'filename' => $originalName,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'size' => $file->getSize(),
                'is_audio_by_extension' => $isAudioByExt,
                'is_audio_by_mime' => $isAudioByMime,
                'is_whatsapp_audio' => $isWhatsAppAudio
            ]);

            // Jika tidak terdeteksi sebagai audio, bisa jadi ada masalah dengan ekstensi file WhatsApp
            if (!$isAudioByExt && !$isAudioByMime && !$isWhatsAppAudio) {
                Log::warning('File not detected as audio but continuing processing:', [
                    'filename' => $originalName,
                    'mime_type' => $mimeType
                ]);
            }

            // Simpan file audio asli terlebih dahulu
            $originalPath = $file->store('sounds/temp', 'public');
            $originalFullPath = storage_path('app/public/' . $originalPath);
            
            // Generate nama file untuk audio yang sudah dikonversi ke Opus/OGG
            $fileName = uniqid('audio_') . '.ogg';
            $convertedPath = 'sounds/' . $fileName;
            $convertedFullPath = storage_path('app/public/' . $convertedPath);
            
            // Konversi audio ke format Opus/OGG menggunakan FFmpeg
            $ffmpegPath = '/usr/bin/ffmpeg'; // Sesuaikan dengan path FFmpeg di server
            
            // Command untuk konversi ke Opus dalam container OGG
            $convertCommand = escapeshellcmd($ffmpegPath . ' -i ' . escapeshellarg($originalFullPath) . 
                ' -c:a libopus -b:a 128k -vbr on -compression_level 10 -y ' . escapeshellarg($convertedFullPath));
            
            Log::info('Running audio conversion command:', [
                'command' => $convertCommand,
                'input' => $originalFullPath,
                'output' => $convertedFullPath
            ]);
            
            // Jalankan konversi audio
            $conversionOutput = [];
            $conversionReturnVar = 0;
            exec($convertCommand . ' 2>&1', $conversionOutput, $conversionReturnVar);
            
            if ($conversionReturnVar !== 0) {
                Log::error('Audio conversion failed:', [
                    'return_code' => $conversionReturnVar,
                    'output' => implode("\n", $conversionOutput)
                ]);
                
                // Fallback: gunakan file asli jika konversi gagal
                $finalAudioPath = $originalPath;
                Log::warning('Using original audio file due to conversion failure');
            } else {
                // Konversi berhasil, gunakan file yang sudah dikonversi
                $finalAudioPath = $convertedPath;
                
                // Hapus file asli yang temporary
                if (file_exists($originalFullPath)) {
                    unlink($originalFullPath);
                }
                
                Log::info('Audio conversion successful:', [
                    'original_format' => $extension,
                    'converted_format' => 'opus/ogg',
                    'output_path' => $convertedPath
                ]);
            }
            
            // Generate spectrogram hanya untuk file audio
            $spectrogramPath = preg_replace('/\.(mp3|wav|ogg|aac|m4a|mp4|opus)$/i', '.png', $finalAudioPath);

            // Set environment variables untuk spectrogram
            $env = [
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PYTHONPATH' => '/var/www/talinara/venv/lib/python3.12/site-packages'
            ];

            $spectrogramCommand = escapeshellcmd("/var/www/talinara/venv/bin/python " . base_path('/scripts/spectrogram.py') . " " .
                storage_path('app/public/' . $finalAudioPath) . " " .
                storage_path('app/public/' . $spectrogramPath));

            Log::info('Running spectrogram command:', [
                'command' => $spectrogramCommand,
                'env' => $env,
                'audio_file' => $finalAudioPath
            ]);

            // Jalankan command spectrogram dengan environment yang sudah diset
            $process = proc_open($spectrogramCommand, [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ], $pipes, null, $env);

            if (is_resource($process)) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                Log::info('Spectrogram generation output:', [
                    'stdout' => $stdout,
                    'stderr' => $stderr
                ]);

                if (!Storage::disk('public')->exists($spectrogramPath)) {
                    Log::warning("Spectrogram generation failed: $stderr");
                    // Tidak throw exception, karena audio tetap bisa disimpan tanpa spectrogram
                    $spectrogramPath = null;
                }
            } else {
                Log::warning('Failed to run spectrogram generation process');
                $spectrogramPath = null;
            }

            return [
                'audioPath' => $finalAudioPath,
                'spectrogramPath' => $spectrogramPath,
                'success' => true,
                'format' => 'opus/ogg',
                'converted' => ($finalAudioPath === $convertedPath)
            ];

        } catch (\Exception $e) {
            Log::error('Error processing audio file: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function processImageFile($file)
    {
        try {
            $uploadPath = storage_path('app/public/observations');
            if (!file_exists($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            // Cek jika file adalah HEIC, konversi ke WebP
            $isHeic = false;
            $originalMimeType = $file->getMimeType();
            $originalExtension = strtolower($file->getClientOriginalExtension());
            
            // Cek apakah file adalah hasil crop
            $isCroppedImage = false;
            if (strpos($file->getClientOriginalName(), 'cropped_image_') === 0) {
                $isCroppedImage = true;
                Log::info('Processing cropped image:', [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $originalMimeType,
                    'extension' => $originalExtension
                ]);
            }
            
            if ($originalMimeType === 'image/heic' || $originalExtension === 'heic') {
                $isHeic = true;
                
                // Log informasi konversi HEIC
                Log::info('Converting HEIC to WebP:', [
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $originalMimeType,
                    'extension' => $originalExtension
                ]);
                
                // Jika Imagick tersedia, gunakan untuk konversi
                if (extension_loaded('imagick')) {
                    try {
                        $tempPath = $file->getRealPath();
                        $imagick = new \Imagick($tempPath);
                        $imagick->setImageFormat('webp');
                        
                        // Simpan file sementara hasil konversi
                        $tempWebpPath = tempnam(sys_get_temp_dir(), 'heic_to_webp_') . '.webp';
                        $imagick->writeImage($tempWebpPath);
                        $imagick->clear();
                        
                        // Ganti file asli dengan hasil konversi
                        $file = new \Illuminate\Http\UploadedFile(
                            $tempWebpPath,
                            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.webp',
                            'image/webp',
                            null,
                            true
                        );
                        
                        Log::info('HEIC conversion successful using Imagick');
                    } catch (\Exception $e) {
                        Log::error('Failed to convert HEIC using Imagick: ' . $e->getMessage());
                    }
                } else {
                    Log::warning('Imagick extension not available for HEIC conversion');
                }
            }

            // Buat instance image
            $image = Image::make($file->getRealPath());

            // Dapatkan orientasi asli
            $width = $image->width();
            $height = $image->height();
            $isPortrait = $height > $width;
            
            // Simpan rasio aspek asli
            $aspectRatio = $width / $height;

            // Set max dimension berdasarkan orientasi
            $maxDimension = 1000; // Tingkatkan ke 1000 untuk kualitas lebih baik

            // Hanya resize jika dimensi melebihi batas maksimal dan bukan hasil crop
            if (!$isCroppedImage) {
                if ($isPortrait && $height > $maxDimension) {
                    // Jika portrait, set height maksimal
                    $image->resize(null, $maxDimension, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                } else if (!$isPortrait && $width > $maxDimension) {
                    // Jika landscape, set width maksimal
                    $image->resize($maxDimension, null, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }
            }

            // Pertahankan orientasi EXIF jika bukan hasil crop
            if (!$isCroppedImage) {
                $image->orientate();
            }
            
            // Tingkatkan brightness dan contrast untuk mencegah gambar menjadi gelap
            // Untuk gambar hasil crop, gunakan nilai yang lebih rendah
            $brightnessValue = $isCroppedImage ? 0 : 0;
            $contrastValue = $isCroppedImage ? 0 : 0;
            
            $image->brightness($brightnessValue);
            $image->contrast($contrastValue);
            
            // Pastikan tidak ada area hitam dengan menggunakan background putih
            $canvas = Image::canvas($image->width(), $image->height(), '#FFFFFF');
            $canvas->insert($image, 'center');
            $image = $canvas;

            // Generate nama file unik dengan ekstensi WebP
            $fileName = uniqid('img_') . '.webp';
            $relativePath = 'observations/' . $fileName;
            $fullPath = storage_path('app/public/' . $relativePath);

            // Simpan sebagai WebP dengan quality 80% untuk kualitas maksimal dan ukuran file lebih kecil
            $image->encode('webp', 80)->save($fullPath);

            // Log informasi gambar
            Log::info('Image processed and converted to WebP:', [
                'original_dimensions' => "$width x $height",
                'new_dimensions' => $image->width() . ' x ' . $image->height(),
                'aspect_ratio' => $aspectRatio,
                'orientation' => $isPortrait ? 'portrait' : 'landscape',
                'path' => $relativePath,
                'format' => 'WebP',
                'was_heic' => $isHeic,
                'was_cropped' => $isCroppedImage,
                'brightness_contrast_applied' => true
            ]);

            return [
                'imagePath' => $relativePath,
                'success' => true,
                'format' => 'webp',
                'dimensions' => [
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'original_width' => $width,
                    'original_height' => $height,
                    'aspect_ratio' => $aspectRatio
                ],
                'orientation' => $isPortrait ? 'portrait' : 'landscape',
                'was_cropped' => $isCroppedImage
            ];

        } catch (\Exception $e) {
            Log::error('Error processing image file: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function store(Request $request)
    {
        try {
            DB::beginTransaction();

            if (!$request->scientific_name) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama spesies harus diisi'
                ], 422);
            }
            
            // Periksa apakah scientific_name adalah pola genus dengan author
            $scientificName = trim($request->scientific_name);
            $nameParts = explode(' ', $scientificName);
            
            // Penanganan khusus untuk genus dengan author kompleks (seperti "Malaxis Sol. ex Sw.")
            if (count($nameParts) >= 3 && in_array('ex', $nameParts)) {
                $firstWord = $nameParts[0];
                
                // Cek jika kata pertama diawali huruf kapital (genus)
                if (preg_match('/^[A-Z][a-z]+$/', $firstWord)) {
                    // Ini adalah pola genus dengan author kompleks, gunakan genus sebagai nilai genus
                    $request->merge(['genus' => $firstWord]);
                    
                    // Jika taxon_rank tidak diset, set ke GENUS
                    if (!$request->taxon_rank) {
                        $request->merge(['taxon_rank' => 'GENUS']);
                    }
                    
                    Log::info("Mendeteksi genus dengan author kompleks di store(), mengatur genus: {$firstWord}");
                }
            }
            
            // Cek jika ini kemungkinan genus dengan author (2 kata, kata kedua diawali huruf kapital atau diakhiri titik)
            else if (count($nameParts) == 2) {
                $firstWord = $nameParts[0];
                $secondWord = $nameParts[1];
                
                // Daftar author umum
                $commonAuthorPatterns = [
                    'Lindl.', 'Sw.', 'Jungh.', 'Vriese', 'L.', 'Linn.', 'Hook.', 'Miq.', 'Blume', 'Roxb.',
                    'Wall.', 'Thunb.', 'Benth.', 'Merr.', 'Baker', 'DC.', 'Span.', 'Bl.',
                    'Burm.f.', 'Korth.', 'J.Smith', 'C.B.Clarke', 'Zoll.', 'R.Br.',
                    'Jack', 'Lam.', 'Gaertn.', 'Steud.', 'Nees', 'C.B.Rob', 'Sm.', 'Pers.',
                    'Hassk.', 'Vahl', 'King', 'F.M.Bailey', 'Bailey', 'Hemsl.', 'Mast.',
                    'Pierre', 'Rumph.', 'Ridl.', 'Andrews', 'Kurz', 'Koord.', 'Valeton',
                    'Schltr.', 'Rolfe', 'J.J.Sm.', 'Rchb.f.', 'Pfitzer', 'Ames',
                    'Sol.', 'Spreng.', 'Willd.', 'A.Rich.', 'Rchb.', 'Nutt.', 'Muhl.',
                    'Kunth', 'Jacq.', 'Griseb.', 'Endl.', 'Crantz', 'Cav.', 'Britton'
                ];
                
                // Cek jika kata pertama diawali huruf kapital (genus) dan kata kedua adalah author
                if (preg_match('/^[A-Z][a-z]+$/', $firstWord) && 
                    (preg_match('/^[A-Z][a-z]*\.?$/', $secondWord) || in_array($secondWord, $commonAuthorPatterns))) {
                    
                    // Ini adalah pola genus dengan author, gunakan genus sebagai nilai genus
                    $request->merge(['genus' => $firstWord]);
                    
                    // Jika taxon_rank tidak diset, set ke GENUS
                    if (!$request->taxon_rank) {
                        $request->merge(['taxon_rank' => 'GENUS']);
                    }
                    
                    Log::info("Mendeteksi genus dengan author di store(), mengatur genus: {$firstWord}");
                }
            }

            $location = $this->getLocationName(
                $request->input('latitude'),
                $request->input('longitude')
            );

            // Proses taxa dan identifikasi awal
            try {
                $taxaResult = $this->processTaxa($request);
                $checklistId = $taxaResult['checklist_id'];
                $mainTaxaId = $taxaResult['taxa_id'];
                $identificationId = $taxaResult['identification_id'];
                $newSessionId = $taxaResult['new_session_id'];
            } catch (\Exception $e) {
                // CRITICAL FIX: Prevent database submission when taxa not found
                DB::rollBack();
                
                // Check if this is a taxa not found error
                if (strpos($e->getMessage(), 'tidak ditemukan dalam database') !== false) {
                    Log::error('Taxa not found error:', [
                        'scientific_name' => $request->scientific_name,
                        'taxon_rank' => $request->taxon_rank,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Try to find synonym taxa as fallback
                    $synonymResult = $this->findSynonymFallback($request->scientific_name);
                    
                    return response()->json([
                        'success' => false,
                        'message' => 'Taksa tidak ditemukan dalam database',
                        'error_details' => [
                            'type' => 'taxa_not_found',
                            'scientific_name' => $request->scientific_name,
                            'synonym_fallback' => $synonymResult,
                            'suggested_action' => 'Periksa ejaan nama ilmiah atau pilih taksa yang tersedia dari daftar saran'
                        ]
                    ], 422);
                }
                
                // Re-throw other exceptions
                throw $e;
            }

            // Simpan lisensi observasi pada checklist jika dikirim
            if ($request->filled('license_observation')) {
                DB::table('fobi_checklist_taxas')
                    ->where('id', $checklistId)
                    ->update(['license_observation' => $request->input('license_observation')]);
            }

            // Proses media yang diunggah
            if ($request->hasFile('media')) {
                $mediaFiles = is_array($request->file('media')) ? $request->file('media') : [$request->file('media')];
                $isCombined = $request->input('is_combined', false);
                // Jika combined, terima array lisensi per media (urutan sama dengan media_types)
                $mediaLicenses = [];
                if ($isCombined && $request->filled('media_licenses')) {
                    $rawMediaLicenses = $request->input('media_licenses');
                    if (is_string($rawMediaLicenses)) {
                        $decoded = json_decode($rawMediaLicenses, true);
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $mediaLicenses = $decoded;
                        }
                    } elseif (is_array($rawMediaLicenses)) {
                        $mediaLicenses = $rawMediaLicenses;
                    }
                }
                $totalFiles = count($mediaFiles);

                // Validasi kombinasi media
                if ($isCombined && $totalFiles > 1) {
                    $mediaTypes = [];
                    foreach ($mediaFiles as $file) {
                        $mediaTypes[] = strpos($file->getMimeType(), 'image') !== false ? 'photo' : 'audio';
                    }

                    // Validasi kombinasi yang diizinkan
                    $isValidCombination =
                        // Semua foto
                        (count(array_filter($mediaTypes, fn($type) => $type === 'photo')) === count($mediaTypes)) ||
                        // Semua audio
                        (count(array_filter($mediaTypes, fn($type) => $type === 'audio')) === count($mediaTypes)) ||
                        // Kombinasi foto dan audio
                        (in_array('photo', $mediaTypes) && in_array('audio', $mediaTypes));

                    if (!$isValidCombination) {
                        throw new \Exception('Kombinasi media tidak valid. Hanya mendukung: semua foto, semua audio, atau kombinasi foto dan audio');
                    }
                }

                // Proses setiap file media
                foreach ($mediaFiles as $index => $mediaFile) {
                    try {
                        // Deteksi tipe media berdasarkan MIME type dan ekstensi file
                        $mimeType = $mediaFile->getMimeType();
                        $originalName = $mediaFile->getClientOriginalName();
                        $extension = strtolower($mediaFile->getClientOriginalExtension());
                        
                        // Cek apakah file adalah audio berdasarkan ekstensi
                        $isAudioByExt = in_array($extension, ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'mp4']);
                        
                        // Deteksi file WhatsApp Audio
                        $isWhatsAppAudio = 
                            stripos($originalName, 'whatsapp audio') !== false || 
                            stripos($originalName, 'ptt-') !== false || 
                            (preg_match('/audio.*\d{4}-\d{2}-\d{2}.*\d{2}\.\d{2}\.\d{2}/i', $originalName) && $extension === 'aac');
                        
                        // Deteksi tipe dengan gabungan MIME type, ekstensi, dan nama file
                        $mediaType = (strpos($mimeType, 'image') !== false) ? 'photo' : 
                                   ((strpos($mimeType, 'audio') !== false || $isAudioByExt || $isWhatsAppAudio) ? 'audio' : 'photo');
                        
                        // Log untuk debugging tipe media
                        Log::info('Media type detection:', [
                            'filename' => $originalName,
                            'mime_type' => $mimeType,
                            'extension' => $extension,
                            'detected_type' => $mediaType,
                            'is_audio_by_extension' => $isAudioByExt,
                            'is_whatsapp_audio' => $isWhatsAppAudio
                        ]);
                        
                        $path = null;
                        $spectrogramPath = null;

                        if ($mediaType === 'photo') {
                            $result = $this->processImageFile($mediaFile);
                            if ($result['success']) {
                                $path = $result['imagePath'];
                            } else {
                                throw new \Exception($result['error']);
                            }
                        } else {
                            $result = $this->processAudioFile($mediaFile);
                            if ($result['success']) {
                                $path = $result['audioPath'];
                                $spectrogramPath = $result['spectrogramPath'];
                            } else {
                                throw new \Exception($result['error']);
                            }
                        }

                        // Tentukan lisensi media
                        $mediaLicense = null;
                        if ($isCombined && !empty($mediaLicenses)) {
                            // Ambil lisensi berdasarkan urutan index
                            $mediaLicense = $mediaLicenses[$index] ?? null;
                        } else {
                            // Non-combined: gunakan license_photo atau license_audio dari request
                            if ($mediaType === 'photo') {
                                $mediaLicense = $request->input('license_photo');
                            } else if ($mediaType === 'audio') {
                                $mediaLicense = $request->input('license_audio');
                            }
                        }

                        // Log untuk debugging
                        Log::info('Saving media to database:', [
                            'mediaType' => $mediaType,
                            'path' => $path,
                            'spectrogramPath' => $spectrogramPath,
                            'license' => $mediaLicense
                        ]);

                        // Simpan media dengan validasi
                        if (!$path) {
                            throw new \Exception('Path file tidak valid');
                        }

                        DB::table('fobi_checklist_media')->insert([
                            'checklist_id' => $checklistId,
                            'media_type' => $mediaType,
                            'license' => $mediaLicense,
                            'file_path' => $path,
                            'spectrogram' => $spectrogramPath,
                            'scientific_name' => $request->input('scientific_name'),
                            'location' => $location,
                            'habitat' => $request->habitat ?? 'Unknown Habitat',
                            'description' => $request->description ?? '',
                            'date' => $request->date ?? now(),
                            'status' => 0, // Tambahkan status
                            'is_combined' => $isCombined && $totalFiles > 1,
                            'combined_order' => ($isCombined && $totalFiles > 1) ? $index : null,
                            'combined_type' => ($isCombined && $totalFiles > 1) ? $this->determineCombinedType($mediaTypes) : null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);

                    } catch (\Exception $e) {
                        Log::error('Error processing media:', [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                        throw $e;
                    }
                }
            }

            // Update atau buat quality assessment
            $taxaData = (object)[
                'id' => $checklistId,
                'created_at' => $request->tgl_pengamatan ?? now(),
                'location' => $location,
                'media' => $request->hasFile('media'),
                'scientific_name' => $request->scientific_name
            ];

            $qualityAssessment = $this->assessTaxaQuality($taxaData);

            // Update jika sudah ada, buat baru jika belum
            TaxaQualityAssessment::updateOrCreate(
                ['taxa_id' => $checklistId],
                [
                    'taxon_id' => $mainTaxaId, // Gunakan ID dari tabel taxas
                    'grade' => $qualityAssessment['grade'],
                    'has_date' => $qualityAssessment['has_date'],
                    'has_location' => $qualityAssessment['has_location'],
                    'has_media' => $qualityAssessment['has_media'],
                    'is_wild' => $qualityAssessment['is_wild'],
                    'location_accurate' => $qualityAssessment['location_accurate'],
                    'recent_evidence' => $qualityAssessment['recent_evidence'],
                    'related_evidence' => $qualityAssessment['related_evidence'],
                    'needs_id' => $qualityAssessment['needs_id'],
                    'community_id_level' => $qualityAssessment['community_id_level']
                ]
            );

            DB::commit();

            // Prepare response data
            $responseData = [
                'success' => true,
                'message' => 'Data berhasil disimpan',
                'checklist_id' => $checklistId,
                'identification_id' => $identificationId,
                'new_session_id' => $newSessionId,
                'quality_assessment' => $qualityAssessment,
                'total_media' => $request->hasFile('media') ? count($mediaFiles) : 0,
                'is_combined' => $isCombined ?? false
            ];

            // Add synonym fallback metadata if used
            if ($request->has('synonym_fallback_used') && $request->synonym_fallback_used) {
                $responseData['synonym_fallback'] = [
                    'used' => true,
                    'original_name' => $request->original_scientific_name,
                    'synonym_name' => $request->synonym_scientific_name,
                    'synonym_taxa_id' => $request->synonym_taxa_id
                ];
            }

            return response()->json($responseData, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving data: ' . $e->getMessage());
            
            // Enhanced error handling for taxa not found
            if (strpos($e->getMessage(), 'tidak ditemukan dalam database') !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taksa tidak ditemukan dalam database',
                    'error_details' => [
                        'type' => 'taxa_not_found',
                        'scientific_name' => $request->scientific_name ?? 'Unknown',
                        'suggested_action' => 'Periksa ejaan nama ilmiah atau pilih taksa yang tersedia dari daftar saran'
                    ]
                ], 422);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menyimpan data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Tambahkan method helper baru
    private function determineCombinedType($mediaTypes)
    {
        $hasPhoto = in_array('photo', $mediaTypes);
        $hasAudio = in_array('audio', $mediaTypes);

        if ($hasPhoto && $hasAudio) {
            return 'mixed';
        } elseif ($hasPhoto) {
            return 'photos';
        } elseif ($hasAudio) {
            return 'audios';
        }

        return null;
    }

    private function processTaxa($request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            // Ekstrak nama genus dari scientific_name jika ini adalah pola genus dengan author
            $scientificName = $request->scientific_name;
            $nameParts = explode(' ', $scientificName);
            
            // Penanganan khusus untuk genus dengan author kompleks (seperti "Malaxis Sol. ex Sw.")
            if (count($nameParts) >= 3 && in_array('ex', $nameParts)) {
                $firstWord = $nameParts[0];
                
                // Cek jika kata pertama diawali huruf kapital (genus)
                if (preg_match('/^[A-Z][a-z]+$/', $firstWord)) {
                    // Ini adalah pola genus dengan author kompleks, gunakan genus sebagai nilai genus
                    $request->merge(['genus' => $firstWord]);
                    
                    // Jika taxon_rank tidak diset, set ke GENUS
                    if (!$request->taxon_rank) {
                        $request->merge(['taxon_rank' => 'GENUS']);
                    }
                    
                    Log::info("Mendeteksi genus dengan author kompleks, mengatur genus: {$firstWord}");
                }
            }
            
            // Cek jika ini kemungkinan genus dengan author (2 kata, kata kedua diawali huruf kapital atau diakhiri titik)
            else if (count($nameParts) == 2) {
                $firstWord = $nameParts[0];
                $secondWord = $nameParts[1];
                
                // Daftar author umum
                $commonAuthorPatterns = [
                    'Lindl.', 'Sw.', 'Jungh.', 'Vriese', 'L.', 'Linn.', 'Hook.', 'Miq.', 'Blume', 'Roxb.',
                    'Wall.', 'Thunb.', 'Benth.', 'Merr.', 'Baker', 'DC.', 'Span.', 'Bl.',
                    'Burm.f.', 'Korth.', 'J.Smith', 'C.B.Clarke', 'Zoll.', 'R.Br.',
                    'Jack', 'Lam.', 'Gaertn.', 'Steud.', 'Nees', 'C.B.Rob', 'Sm.', 'Pers.',
                    'Hassk.', 'Vahl', 'King', 'F.M.Bailey', 'Bailey', 'Hemsl.', 'Mast.',
                    'Pierre', 'Rumph.', 'Ridl.', 'Andrews', 'Kurz', 'Koord.', 'Valeton',
                    'Schltr.', 'Rolfe', 'J.J.Sm.', 'Rchb.f.', 'Pfitzer', 'Ames',
                    'Sol.', 'Spreng.', 'Willd.', 'A.Rich.', 'Rchb.', 'Nutt.', 'Muhl.',
                    'Kunth', 'Jacq.', 'Griseb.', 'Endl.', 'Crantz', 'Cav.', 'Britton'
                ];
                
                // Cek jika kata pertama diawali huruf kapital (genus) dan kata kedua adalah author
                if (preg_match('/^[A-Z][a-z]+$/', $firstWord) && 
                    (preg_match('/^[A-Z][a-z]*\.?$/', $secondWord) || in_array($secondWord, $commonAuthorPatterns))) {
                    
                    // Ini adalah pola genus dengan author, gunakan genus sebagai nilai genus
                    $request->merge(['genus' => $firstWord]);
                    
                    // Jika taxon_rank tidak diset, set ke GENUS
                    if (!$request->taxon_rank) {
                        $request->merge(['taxon_rank' => 'GENUS']);
                    }
                    
                    Log::info("Mendeteksi genus dengan author, mengatur genus: {$firstWord}");
                }
            }

            // Dapatkan taxa_id dari tabel taxas
            $mainTaxaId = $this->getOrCreateMainTaxa($request);

            DB::beginTransaction();

            // Buat checklist baru
            $checklistId = DB::table('fobi_checklist_taxas')->insertGetId([
                'taxa_id' => $mainTaxaId,
                'user_id' => $user->id,
                'media_id' => null,
                'scientific_name' => $request->scientific_name,
                'class' => $request->input('class'),          // Gunakan input() untuk memastikan
                'order' => $request->input('order'),          // nilai null ditangani dengan benar
                'family' => $request->input('family'),
                'genus' => $request->input('genus'),
                'species' => $request->input('species'),
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'observation_details' => $request->additional_note,
                'upload_session_id' => $request->upload_session_id,
                'date' => $request->date,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Simpan identifikasi awal ke taxa_identifications
            $identificationId = DB::table('taxa_identifications')->insertGetId([
                'checklist_id' => $checklistId,
                'user_id' => $user->id,
                'taxon_id' => $mainTaxaId,
                'identification_level' => $this->determineIdentificationLevel($request),
                'comment' => $request->additional_note,
                'user_agreed' => false,
                'agreement_count' => 0,
                'is_main' => true,
                'is_first' => true,
                'created_at' => now(),
                'updated_at' => now(),
                'burnes_checklist_id' => null,
                'kupnes_checklist_id' => null
            ]);

            DB::commit();

            Log::info('Membuat checklist dan identifikasi awal:', [
                'checklist_id' => $checklistId,
                'identification_id' => $identificationId,
                'upload_session_id' => $request->upload_session_id,
                'taxa_id' => $mainTaxaId
            ]);

            $newSessionId = $this->generateNewSessionId($user->id);

            return [
                'checklist_id' => $checklistId,
                'taxa_id' => $mainTaxaId,
                'identification_id' => $identificationId,
                'new_session_id' => $newSessionId
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing taxa:', [
                'error' => $e->getMessage(),
                'upload_session_id' => $request->upload_session_id ?? null,
                'taxa_id' => $mainTaxaId ?? null
            ]);
            throw $e;
        }
    }

    private function determineIdentificationLevel($request)
    {
        if ($request->species) {
            return 'species';
        } elseif ($request->genus) {
            return 'genus';
        } elseif ($request->family) {
            return 'family';
        } elseif ($request->order) {
            return 'order';
        } elseif ($request->class) {
            return 'class';
        } else {
            return 'unknown';
        }
    }

    // Method baru untuk generate session ID
    private function generateNewSessionId($userId)
    {
        return "obs_{$userId}_" . time() . "_" . uniqid();
    }

    // Method untuk mencari synonym sebagai fallback
    private function findSynonymFallback($scientificName)
    {
        try {
            // Cari taxa dengan taxonomic_status = 'SYNONYM' yang cocok dengan nama
            $synonymTaxa = DB::table('taxas')
                ->where('scientific_name', 'LIKE', "%{$scientificName}%")
                ->where('taxonomic_status', 'SYNONYM')
                ->first();

            if ($synonymTaxa) {
                Log::info("Found synonym fallback for: {$scientificName}", [
                    'synonym_id' => $synonymTaxa->id,
                    'synonym_name' => $synonymTaxa->scientific_name,
                    'taxonomic_status' => $synonymTaxa->taxonomic_status
                ]);

                return [
                    'id' => $synonymTaxa->id,
                    'scientific_name' => $synonymTaxa->scientific_name,
                    'taxonomic_status' => $synonymTaxa->taxonomic_status,
                    'taxon_rank' => $synonymTaxa->taxon_rank ?? null
                ];
            }

            // Jika tidak ada synonym yang cocok, coba cari dengan pattern matching yang lebih fleksibel
            $nameParts = explode(' ', $scientificName);
            if (count($nameParts) >= 2) {
                $genus = $nameParts[0];
                $species = $nameParts[1];
                
                $flexibleSynonym = DB::table('taxas')
                    ->where('genus', $genus)
                    ->where('species', 'LIKE', "%{$species}%")
                    ->where('taxonomic_status', 'SYNONYM')
                    ->first();

                if ($flexibleSynonym) {
                    Log::info("Found flexible synonym fallback for: {$scientificName}", [
                        'synonym_id' => $flexibleSynonym->id,
                        'synonym_name' => $flexibleSynonym->scientific_name
                    ]);

                    return [
                        'id' => $flexibleSynonym->id,
                        'scientific_name' => $flexibleSynonym->scientific_name,
                        'taxonomic_status' => $flexibleSynonym->taxonomic_status,
                        'taxon_rank' => $flexibleSynonym->taxon_rank ?? null
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Error finding synonym fallback:', [
                'scientific_name' => $scientificName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    // Method untuk memproses taxa dengan synonym fallback
    public function processWithSynonymFallback(Request $request)
    {
        try {
            DB::beginTransaction();

            // Validasi input
            if (!$request->scientific_name) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nama spesies harus diisi'
                ], 422);
            }

            $originalScientificName = $request->scientific_name;
            
            // Cari synonym fallback berdasarkan scientific_name dari request
            $synonymFallback = $this->findSynonymFallback($originalScientificName);
            
            if (!$synonymFallback) {
                // Jika tidak ada synonym, fallback ke Unknown
                $request->merge([
                    'scientific_name' => 'Unknown',
                    'taxon_rank' => 'UNKNOWN',
                    'taxonomic_status' => 'UNKNOWN',
                    'original_scientific_name' => $originalScientificName
                ]);
            } else {
                // Update request dengan data synonym
                $request->merge([
                    'scientific_name' => $synonymFallback['scientific_name'],
                    'taxon_rank' => $synonymFallback['taxon_rank'],
                    'taxonomic_status' => 'SYNONYM',
                    'original_scientific_name' => $originalScientificName
                ]);
            }

            // Proses seperti biasa dengan data yang sudah diupdate
            $result = $this->store($request);
            
            DB::commit();
            
            // Tambahkan informasi bahwa ini menggunakan fallback
            if ($result->getStatusCode() === 200) {
                $responseData = json_decode($result->getContent(), true);
                $responseData['used_fallback'] = true;
                $responseData['original_scientific_name'] = $originalScientificName;
                
                if ($synonymFallback) {
                    $responseData['used_synonym_fallback'] = true;
                    $responseData['synonym_data'] = $synonymFallback;
                } else {
                    $responseData['used_unknown_fallback'] = true;
                }
                
                return response()->json($responseData);
            }

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing with synonym fallback:', [
                'error' => $e->getMessage(),
                'scientific_name' => $request->scientific_name
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses dengan synonym fallback'
            ], 500);
        }
    }

    public function generateUploadSession()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $sessionId = $this->generateNewSessionId($user->id);

            return response()->json([
                'success' => true,
                'upload_session_id' => $sessionId
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat session ID'
            ], 500);
        }
    }

    // Fungsi helper untuk mendapatkan atau membuat taxa di tabel utama
    private function getOrCreateMainTaxa($request)
    {
        try {
            $scientificName = trim($request->scientific_name);
            $nameParts = explode(' ', $scientificName);
            
            // Log request untuk debugging
            Log::info('getOrCreateMainTaxa request data:', [
                'scientific_name' => $scientificName,
                'taxon_rank' => $request->taxon_rank ?? null,
                'name_parts' => $nameParts
            ]);
            
            // Try to find taxa first, if not found, attempt synonym fallback
            $result = $this->findTaxaWithFallback($scientificName, $request);
            
            if ($result['found']) {
                // Store synonym fallback info in request for later use
                if ($result['used_synonym']) {
                    $request->merge([
                        'synonym_fallback_used' => true,
                        'original_scientific_name' => $scientificName,
                        'synonym_scientific_name' => $result['synonym_name'],
                        'synonym_taxa_id' => $result['taxa_id']
                    ]);
                }
                return $result['taxa_id'];
            }
            
            // If no synonym fallback found, continue with original logic
            
            // Penanganan khusus untuk Unknown
            if ($scientificName === 'Unknown') {
                // Prioritaskan rank yang dikirim dari frontend
                $requestRank = $request->taxon_rank ? strtoupper($request->taxon_rank) : 'UNKNOWN';
                
                // Coba ambil "Unknown" dengan rank yang diminta dari database
                $unknownTaxa = DB::table('taxas')
                    ->where('scientific_name', 'Unknown')
                    ->where('taxon_rank', $requestRank)
                    ->first();
                
                if ($unknownTaxa) {
                    Log::info("Menggunakan Unknown taxa yang sudah ada dengan rank: {$requestRank}");
                    return $unknownTaxa->id;
                }
                
                // Jika tidak ditemukan, buat entri baru untuk "Unknown" dengan rank yang diminta
                $unknownTaxaId = DB::table('taxas')->insertGetId([
                    'scientific_name' => 'Unknown',
                    'taxon_rank' => $requestRank,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                Log::info("Membuat Unknown taxa baru dengan rank: {$requestRank}", [
                    'id' => $unknownTaxaId
                ]);
                
                return $unknownTaxaId;
            }
            
            // Prioritaskan rank yang dikirim dari frontend
            $requestRank = $request->taxon_rank ? strtoupper($request->taxon_rank) : null;
            
            // Deteksi rank dari nama jika tidak ada di request
            $taxonRank = $requestRank ?? $this->detectRankFromName($scientificName);
            
            // Penanganan khusus untuk nama genus dengan author kompleks (seperti "Malaxis Sol. ex Sw.")
            if (count($nameParts) >= 3 && in_array('ex', $nameParts)) {
                $firstWord = $nameParts[0];
                
                // Cek jika kata pertama diawali huruf kapital (genus)
                if (preg_match('/^[A-Z][a-z]+$/', $firstWord)) {
                    Log::info("Penanganan khusus untuk genus dengan author kompleks: {$scientificName}");
                    
                    // Cari genus di database
                    $genusQuery = DB::table('taxas')
                        ->where('genus', $firstWord)
                        ->where('taxon_rank', 'GENUS')
                        ->first();
                    
                    if ($genusQuery) {
                        Log::info("Menemukan genus yang cocok di database: {$firstWord}", [
                            'id' => $genusQuery->id
                        ]);
                        return $genusQuery->id;
                    } else {
                        // Jika tidak ditemukan, coba cari dengan nama genus saja (tanpa author)
                        $genusOnlyQuery = DB::table('taxas')
                            ->where('scientific_name', $firstWord)
                            ->where('taxon_rank', 'GENUS')
                            ->first();
                        
                        if ($genusOnlyQuery) {
                            Log::info("Menemukan genus dengan nama scientific: {$firstWord}", [
                                'id' => $genusOnlyQuery->id
                            ]);
                            return $genusOnlyQuery->id;
                        } else {
                            // Jika masih tidak ditemukan, buat entri baru untuk genus
                            $taxonRank = 'GENUS';
                            Log::info("Mengubah rank ke GENUS untuk nama dengan pola genus+author kompleks: {$scientificName}");
                            
                            // Gunakan hanya nama genus (tanpa author) untuk scientific_name
                            $scientificName = $firstWord;
                        }
                    }
                }
            }
            
            // Penanganan khusus untuk nama genus dengan author (seperti "Coelogyne Lindl.", "Dendrobium Sw.")
            if (count($nameParts) == 2) {
                $firstWord = $nameParts[0];
                $secondWord = $nameParts[1];
                
                // Daftar author umum untuk tumbuhan
                $commonAuthorPatterns = [
                    'Lindl.', 'Sw.', 'Jungh.', 'Vriese', 'L.', 'Linn.', 'Hook.', 'Miq.', 'Blume', 'Roxb.',
                    'Wall.', 'Thunb.', 'Benth.', 'Merr.', 'Baker', 'DC.', 'Span.', 'Bl.',
                    'Burm.f.', 'Korth.', 'J.Smith', 'C.B.Clarke', 'Zoll.', 'R.Br.',
                    'Jack', 'Lam.', 'Gaertn.', 'Steud.', 'Nees', 'C.B.Rob', 'Sm.', 'Pers.',
                    'Hassk.', 'Vahl', 'King', 'F.M.Bailey', 'Bailey', 'Hemsl.', 'Mast.',
                    'Pierre', 'Rumph.', 'Ridl.', 'Andrews', 'Kurz', 'Koord.', 'Valeton',
                    'Schltr.', 'Rolfe', 'J.J.Sm.', 'Rchb.f.', 'Pfitzer', 'Ames',
                    'Sol.', 'Spreng.', 'Willd.', 'A.Rich.', 'Rchb.', 'Nutt.', 'Muhl.',
                    'Kunth', 'Jacq.', 'Griseb.', 'Endl.', 'Crantz', 'Cav.', 'Britton'
                ];
                
                // Cek jika kata pertama diawali huruf kapital (genus) dan kata kedua adalah author
                if (preg_match('/^[A-Z][a-z]+$/', $firstWord) && 
                    (preg_match('/^[A-Z][a-z]*\.?$/', $secondWord) || in_array($secondWord, $commonAuthorPatterns))) {
                    
                    Log::info("Penanganan khusus untuk genus dengan author: {$scientificName}");
                    
                    // Cari genus di database
                    $genusQuery = DB::table('taxas')
                        ->where('genus', $firstWord)
                        ->where('taxon_rank', 'GENUS')
                        ->first();
                    
                    if ($genusQuery) {
                        Log::info("Menemukan genus yang cocok di database: {$firstWord}", [
                            'id' => $genusQuery->id
                        ]);
                        return $genusQuery->id;
                    } else {
                        // Jika tidak ditemukan, coba cari dengan nama genus saja (tanpa author)
                        $genusOnlyQuery = DB::table('taxas')
                            ->where('scientific_name', $firstWord)
                            ->where('taxon_rank', 'GENUS')
                            ->first();
                        
                        if ($genusOnlyQuery) {
                            Log::info("Menemukan genus dengan nama scientific: {$firstWord}", [
                                'id' => $genusOnlyQuery->id
                            ]);
                            return $genusOnlyQuery->id;
                        } else {
                            // Jika masih tidak ditemukan, buat entri baru untuk genus
                            $taxonRank = 'GENUS';
                            Log::info("Mengubah rank ke GENUS untuk nama dengan pola genus+author: {$scientificName}");
                            
                            // Gunakan hanya nama genus (tanpa author) untuk scientific_name
                            $scientificName = $firstWord;
                        }
                    }
                }
            }
            
            // Penanganan khusus untuk taksa tertentu yang sering salah dideteksi
            $specialTaxaRanks = [
                'Teleostei' => 'CLASS',
                'Actinopterygii' => 'CLASS',
                'Mammalia' => 'CLASS',
                'Aves' => 'CLASS',
                'Reptilia' => 'CLASS',
                'Amphibia' => 'CLASS',
                'Chondrichthyes' => 'CLASS',
                'Elasmobranchii' => 'SUBCLASS',
                'Insecta' => 'CLASS',
                'Arachnida' => 'CLASS',
                'Crustacea' => 'SUBPHYLUM',
                'Plantae' => 'KINGDOM',
                'Animalia' => 'KINGDOM',
                'Fungi' => 'KINGDOM',
                'Protista' => 'KINGDOM',
                'Monera' => 'KINGDOM',
                'Unknown' => 'UNKNOWN',
                
                
                // ... daftar lainnya tetap sama ...
            ];
            
            // Periksa jika nama spesies ada dalam daftar khusus
            if (array_key_exists($scientificName, $specialTaxaRanks)) {
                $taxonRank = $specialTaxaRanks[$scientificName];
                Log::info("Menerapkan penanganan khusus untuk: {$scientificName}, rank: {$taxonRank}");
            }
            
            // Override taxonRank dengan requestRank jika tersedia
            if ($requestRank) {
                $taxonRank = $requestRank;
                Log::info("Menggunakan rank dari request: {$taxonRank}");
            }
            
            // Jika SPECIES dengan 3+ kata, periksa apakah ini species tumbuhan (Plantae)
            if ($taxonRank === 'SPECIES' && count($nameParts) >= 3) {
                $genusName = $nameParts[0];
                // Periksa apakah ini tumbuhan dari database
                $plantCheck = DB::table('taxas')
                    ->where('genus', $genusName)
                    ->where('kingdom', 'Plantae')
                    ->first();
                    
                if ($plantCheck) {
                    Log::info("Memastikan {$scientificName} adalah SPECIES berdasarkan cek database kingdom Plantae");
                    $taxonRank = 'SPECIES';
                }
            }
            
            // Buat scientific_name yang sesuai format
            $formattedScientificName = $this->formatScientificName($scientificName, $taxonRank);
            
            // ... kode selanjutnya tetap sama ...
            
            // Query dasar
            $query = DB::table('taxas');
            
            // Penanganan khusus berdasarkan rank
            switch ($taxonRank) {
                case 'SPECIES':
                    if (count($nameParts) < 2) {
                        throw new \Exception("Nama species harus terdiri dari genus dan species");
                    }
                    $genus = $nameParts[0];
                    $species = $nameParts[1];
                    
                    // Enhanced species search with multiple fallback strategies
                    $query->where(function($q) use ($genus, $species, $scientificName, $formattedScientificName) {
                        // Primary search: exact genus and species match
                        $q->where(function($subQ) use ($genus, $species) {
                            $subQ->where('genus', $genus)
                                 ->where('species', $species);
                        })
                        // Secondary search: genus exact, species with LIKE
                        ->orWhere(function($subQ) use ($genus, $species) {
                            $subQ->where('genus', $genus)
                                 ->where('species', 'LIKE', "$species%");
                        })
                        // Tertiary search: scientific_name exact match
                        ->orWhere('scientific_name', $scientificName)
                        // Quaternary search: scientific_name with genus + species only
                        ->orWhere('scientific_name', "$genus $species")
                        // Fallback: scientific_name LIKE match for author citations
                        ->orWhere('scientific_name', 'LIKE', "$genus $species%");
                    })->where('taxon_rank', 'SPECIES');
                    break;
                    
                case 'SUBSPECIES':
                    if (count($nameParts) < 3) {
                        throw new \Exception("Nama subspecies harus terdiri dari genus, species, dan subspecies");
                    }
                    $genus = $nameParts[0];
                    $species = $nameParts[1];
                    $subspecies = '';
                    
                    // Cari bagian subspecies
                    for ($i = 2; $i < count($nameParts); $i++) {
                        if (!in_array(strtolower($nameParts[$i]), ['subsp.', 'ssp.', 'subspecies'])) {
                            $subspecies = $nameParts[$i];
                            break;
                        }
                    }
                    
                    $query->where(function($q) use ($genus, $species, $subspecies, $formattedScientificName) {
                        $q->where(function($subQ) use ($genus, $species, $subspecies) {
                            $subQ->where('genus', $genus)
                                 ->where('species', 'LIKE', "$species%")
                                 ->where('subspecies', 'LIKE', "%$subspecies%");
                        })->orWhere('scientific_name', 'LIKE', "$genus $species $subspecies%")
                          ->orWhere('scientific_name', 'LIKE', "$genus $species subsp. $subspecies%");
                    })->where('taxon_rank', 'SUBSPECIES');
                    break;
                    
                case 'VARIETY':
                    if (count($nameParts) < 4) {
                        throw new \Exception("Nama variety harus terdiri dari genus, species, dan variety");
                    }
                    $genus = $nameParts[0];
                    $species = $nameParts[1];
                    $variety = '';
                    
                    // Cari bagian variety
                    for ($i = 2; $i < count($nameParts); $i++) {
                        if (!in_array(strtolower($nameParts[$i]), ['var.', 'variety'])) {
                            $variety = $nameParts[$i];
                            break;
                        }
                    }
                    
                    $query->where(function($q) use ($genus, $species, $variety) {
                        $q->where(function($subQ) use ($genus, $species, $variety) {
                            $subQ->where('genus', $genus)
                                 ->where('species', 'LIKE', "$species%")
                                 ->where('variety', 'LIKE', "%$variety%");
                        })->orWhere('scientific_name', 'LIKE', "$genus $species var. $variety%");
                    })->where('taxon_rank', 'VARIETY');
                    break;
                    
                case 'FORM':
                    if (count($nameParts) < 4) {
                        throw new \Exception("Nama form harus terdiri dari genus, species, dan form");
                    }
                    $genus = $nameParts[0];
                    $species = $nameParts[1];
                    $form = '';
                    
                    // Cari bagian form
                    for ($i = 2; $i < count($nameParts); $i++) {
                        if (!in_array(strtolower($nameParts[$i]), ['f.', 'form', 'forma'])) {
                            $form = $nameParts[$i];
                            break;
                        }
                    }
                    
                    $query->where('genus', $genus)
                          ->where('species', 'LIKE', "$species%")
                          ->where('form', 'LIKE', "%$form%")
                          ->where('taxon_rank', 'FORM');
                    break;
                    
                case 'GENUS':
                    // Pencarian lebih fleksibel untuk genus
                    $query->where(function($q) use ($nameParts) {
                        $q->where('genus', $nameParts[0])
                          ->where('taxon_rank', 'GENUS');
                    });
                    break;
                    
                case 'FAMILY':
                    $query->where('family', 'LIKE', "%$scientificName%")
                          ->where('taxon_rank', 'FAMILY');
                    break;
                    
                case 'ORDER':
                    $query->where('order', 'LIKE', "%$scientificName%")
                          ->where('taxon_rank', 'ORDER');
                    break;
                    
                case 'CLASS':
                    $query->where('class', 'LIKE', "%$scientificName%")
                          ->where('taxon_rank', 'CLASS');
                    break;
                    
                case 'PHYLUM':
                    $query->where('phylum', 'LIKE', "%$scientificName%")
                          ->where('taxon_rank', 'PHYLUM');
                    break;
                    
                case 'KINGDOM':
                    $query->where('kingdom', 'LIKE', "%$scientificName%")
                          ->where('taxon_rank', 'KINGDOM');
                    break;
                    
                case 'UNKNOWN':
                    // Cari taksa Unknown yang cocok
                    $query->where('scientific_name', $scientificName)
                          ->where('taxon_rank', 'UNKNOWN');
                    break;
                    
                default:
                    // Untuk rank lainnya, cari di scientific_name dan kolom spesifik
                    $query->where(function($q) use ($scientificName, $taxonRank) {
                        $q->where('scientific_name', 'LIKE', "%$scientificName%")
                          ->orWhere(DB::raw('LOWER(taxon_rank)'), '=', strtolower($taxonRank));
                    })->where('taxon_rank', $taxonRank);
                    break;
            }
            
            // Debug query
            Log::info('Taxa search query:', [
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
                'scientific_name' => $scientificName,
                'formatted_name' => $formattedScientificName,
                'taxon_rank' => $taxonRank,
                'name_parts' => $nameParts
            ]);
            
            $match = $query->first();
            
            if (!$match) {
                // Enhanced fallback logic for different taxa ranks
                if ($taxonRank == 'SPECIES') {
                    // Additional fallback searches for species
                    $genus = $nameParts[0];
                    $species = $nameParts[1];
                    
                    Log::info("Species not found with primary search, trying fallbacks", [
                        'genus' => $genus,
                        'species' => $species,
                        'original_name' => $scientificName
                    ]);
                    
                    // Try searching with just genus and species (ignore author citations)
                    $fallbackQuery = DB::table('taxas')
                        ->where('genus', $genus)
                        ->where('species', $species)
                        ->where('taxon_rank', 'SPECIES')
                        ->first();
                    
                    if ($fallbackQuery) {
                        Log::info("Found species match with genus+species fallback", [
                            'match_id' => $fallbackQuery->id,
                            'match_name' => $fallbackQuery->scientific_name
                        ]);
                        return $fallbackQuery->id;
                    }
                    
                    // Try case-insensitive search
                    $caseInsensitiveQuery = DB::table('taxas')
                        ->whereRaw('LOWER(genus) = ?', [strtolower($genus)])
                        ->whereRaw('LOWER(species) = ?', [strtolower($species)])
                        ->where('taxon_rank', 'SPECIES')
                        ->first();
                    
                    if ($caseInsensitiveQuery) {
                        Log::info("Found species match with case-insensitive search", [
                            'match_id' => $caseInsensitiveQuery->id,
                            'match_name' => $caseInsensitiveQuery->scientific_name
                        ]);
                        return $caseInsensitiveQuery->id;
                    }
                    
                    // Try searching in scientific_name with partial match
                    $scientificNameQuery = DB::table('taxas')
                        ->where('scientific_name', 'LIKE', "$genus $species%")
                        ->where('taxon_rank', 'SPECIES')
                        ->first();
                    
                    if ($scientificNameQuery) {
                        Log::info("Found species match with scientific_name partial search", [
                            'match_id' => $scientificNameQuery->id,
                            'match_name' => $scientificNameQuery->scientific_name
                        ]);
                        return $scientificNameQuery->id;
                    }
                }
                
                // Jika tidak menemukan dengan rank yang diberikan, coba pencarian alternatif
                if ($taxonRank == 'GENUS') {
                    // Coba cari di tingkat class jika sebelumnya mencari sebagai genus
                    $alternativeRanks = ['CLASS', 'ORDER', 'FAMILY'];
                    
                    foreach ($alternativeRanks as $altRank) {
                        $altQuery = DB::table('taxas')
                            ->where(strtolower($altRank), 'LIKE', "%$scientificName%")
                            ->where('taxon_rank', $altRank);
                            
                        Log::info("Mencoba pencarian alternatif dengan rank: $altRank", [
                            'sql' => $altQuery->toSql(),
                            'bindings' => $altQuery->getBindings()
                        ]);
                        
                        $altMatch = $altQuery->first();
                        
                        if ($altMatch) {
                            Log::info("Menemukan kecocokan dengan rank alternatif: $altRank", [
                                'match_id' => $altMatch->id,
                                'match_name' => $altMatch->scientific_name
                            ]);
                            return $altMatch->id;
                        }
                    }
                }
                
                // Tambah penanganan khusus untuk rank KINGDOM
                if ($taxonRank == 'KINGDOM') {
                    // Tambahkan kingdom baru
                    $newTaxaId = DB::table('taxas')->insertGetId([
                        'scientific_name' => $scientificName,
                        'kingdom' => $scientificName,
                        'taxon_rank' => 'KINGDOM',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    Log::info("Menambahkan kingdom baru: {$scientificName}", [
                        'id' => $newTaxaId
                    ]);
                    
                    return $newTaxaId;
                }
                
                // Tambah penanganan khusus untuk rank UNKNOWN
                if ($taxonRank == 'UNKNOWN') {
                    // Tambahkan taksa Unknown baru
                    $newTaxaId = DB::table('taxas')->insertGetId([
                        'scientific_name' => $scientificName,
                        'taxon_rank' => 'UNKNOWN',
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    
                    Log::info("Menambahkan taksa unknown baru: {$scientificName}", [
                        'id' => $newTaxaId
                    ]);
                    
                    return $newTaxaId;
                }
                
                // Coba cari dengan searchTaxa
                $searchRequest = new Request([
                    'q' => $scientificName,
                    'rank' => $taxonRank,
                    'source' => $request->source ?? 'fobi'
                ]);
                
                // Penanganan khusus untuk nama spesies tumbuhan dengan author (Plantae)
                if ($taxonRank === 'SUBSPECIES' && count($nameParts) >= 3) {
                    $genusName = $nameParts[0];
                    $potentialAuthor = $nameParts[2] ?? '';
                    
                    // Periksa pola typical author pada nama tumbuhan (diawali huruf kapital)
                    $hasAuthorPattern = preg_match('/^[A-Z]/', $potentialAuthor);
                    $containsAuthorSigns = strpos($scientificName, ' & ') !== false || 
                                         strpos($scientificName, 'Jungh.') !== false ||
                                         strpos($scientificName, 'L.') !== false ||
                                         strpos($scientificName, 'Linn.') !== false;
                    
                    // Daftar genus tumbuhan umum
                    $commonPlantGenera = [
                        'Pinus', 'Quercus', 'Ficus', 'Acacia', 'Eucalyptus', 'Magnolia', 
                        'Oryza', 'Zea', 'Bambusa', 'Shorea', 'Dipterocarpus', 'Artocarpus',
                        'Mangifera', 'Citrus', 'Durio', 'Garcinia', 'Rhizophora', 'Avicennia',
                        'Bruguiera', 'Sonneratia', 'Amorphophallus', 'Rafflesia', 'Nepenthes'
                    ];
                    
                    // Jika ini sepertinya nama spesies tumbuhan dengan author, ganti ke rank SPECIES
                    if ((in_array($genusName, $commonPlantGenera) || $hasAuthorPattern || $containsAuthorSigns)) {
                        Log::info("Mendeteksi potensi spesies tumbuhan dengan author citation, mengubah rank ke SPECIES");
                        $searchRequest = new Request([
                            'q' => $scientificName,
                            'rank' => 'SPECIES',
                            'source' => $request->source ?? 'fobi'
                        ]);
                        
                        // Cek dari database apakah ini genus tumbuhan
                        $isPlantGenus = DB::table('taxas')
                            ->where('genus', $genusName)
                            ->where('kingdom', 'Plantae')
                            ->exists();
                            
                        if ($isPlantGenus) {
                            Log::info("Konfirmasi dari database: {$genusName} adalah genus tumbuhan");
                        }
                    }
                }
                
                $qualityAssessmentController = app(ChecklistQualityAssessmentController::class);
                $searchController = new ChecklistObservationController($qualityAssessmentController);
                $searchResult = $searchController->searchTaxa($searchRequest);
                
                $searchData = json_decode($searchResult->getContent());
                if ($searchData->success && !empty($searchData->data)) {
                    Log::info("Menemukan hasil dari searchTaxa", [
                        'count' => count($searchData->data)
                    ]);
                    
                    // Coba dengan rank yang sama dulu
                    foreach ($searchData->data as $taxa) {
                        if (strtoupper($taxa->rank) === $taxonRank) {
                            Log::info("Menemukan kecocokan di searchTaxa dengan rank yang sama", [
                                'taxa_id' => $taxa->id,
                                'rank' => $taxa->rank
                            ]);
                            return $taxa->id;
                        }
                    }
                    
                    // Jika tidak ada yang cocok dengan rank, ambil item pertama
                    Log::info("Tidak ada yang cocok dengan rank yang sama, mengambil item pertama", [
                        'taxa_id' => $searchData->data[0]->id,
                        'rank' => $searchData->data[0]->rank
                    ]);
                    return $searchData->data[0]->id;
                }
                
                throw new \Exception("Taxa dengan nama '$scientificName' dan rank '$taxonRank' tidak ditemukan dalam database");
            }
            
            return $match->id;
            
        } catch (\Exception $e) {
            // Check for specific error pattern for plant species with authors
            $errorMsg = $e->getMessage();
            $isPlantSpeciesWithAuthorError = (
                strpos($errorMsg, "tidak ditemukan dalam database") !== false && 
                strpos($errorMsg, "SUBSPECIES") !== false && 
                preg_match('/^[A-Z][a-z]+ [a-z]+ [A-Z]/', $scientificName)
            );
            
            if ($isPlantSpeciesWithAuthorError) {
                Log::error("Terdeteksi kesalahan dengan spesies tumbuhan dan author:", [
                    'scientific_name' => $scientificName,
                    'error' => $errorMsg
                ]);
                
                // Coba sekali lagi dengan rank SPECIES untuk penanganan khusus nama spesies tumbuhan
                try {
                    $searchRequest = new Request([
                        'q' => $scientificName,
                        'rank' => 'SPECIES',
                        'source' => $request->source ?? 'fobi'
                    ]);
                    
                    $qualityAssessmentController = app(ChecklistQualityAssessmentController::class);
                    $searchController = new ChecklistObservationController($qualityAssessmentController);
                    $searchResult = $searchController->searchTaxa($searchRequest);
                    
                    $searchData = json_decode($searchResult->getContent());
                    if ($searchData->success && !empty($searchData->data)) {
                        Log::info("Berhasil menemukan spesies tumbuhan pada percobaan khusus:", [
                            'taxa_id' => $searchData->data[0]->id,
                            'rank' => $searchData->data[0]->rank
                        ]);
                        return $searchData->data[0]->id;
                    }
                } catch (\Exception $innerEx) {
                    Log::error("Gagal pada percobaan kedua mencari spesies tumbuhan:", [
                        'error' => $innerEx->getMessage()
                    ]);
                }
                
                // Jika masih gagal, berikan pesan error yang lebih spesifik
                throw new \Exception("Taxa dengan nama '$scientificName' tidak ditemukan. Jika ini adalah spesies tumbuhan dengan author, coba hilangkan bagian author atau cari apakah nama spesies sudah benar.");
            }
        } catch (\Exception $e) {
            Log::error('Error in getOrCreateMainTaxa:', [
                'error' => $e->getMessage(),
                'scientific_name' => $scientificName ?? null,
                'taxon_rank' => $taxonRank ?? null,
                'name_parts' => $nameParts ?? null
            ]);
            
            throw $e;
        }
        
        // This should never be reached, but add safeguard
        Log::error('getOrCreateMainTaxa reached end without returning taxa_id', [
            'scientific_name' => $scientificName ?? null
        ]);
        throw new \Exception("Taxa dengan nama '$scientificName' tidak dapat diproses");
    }

    /**
     * Find taxa with automatic synonym fallback
     */
    private function findTaxaWithFallback($scientificName, $request)
    {
        // First, try to find exact match
        $exactMatch = DB::table('taxas')
            ->where('scientific_name', $scientificName)
            ->first();
            
        if ($exactMatch) {
            // If found but it's a SYNONYM, check if we should use it or find the accepted name
            if ($exactMatch->taxonomic_status === 'SYNONYM') {
                Log::info("Found exact match but it's a SYNONYM: {$scientificName}", [
                    'taxa_id' => $exactMatch->id,
                    'taxonomic_status' => $exactMatch->taxonomic_status
                ]);
                
                // Use the synonym itself since the accepted name might not be in database
                return [
                    'found' => true,
                    'used_synonym' => true,
                    'taxa_id' => $exactMatch->id,
                    'synonym_name' => $exactMatch->scientific_name
                ];
            }
            
            // If it's ACCEPTED, use it directly
            return [
                'found' => true,
                'used_synonym' => false,
                'taxa_id' => $exactMatch->id,
                'synonym_name' => null
            ];
        }
        
        // If not found, try flexible synonym fallback
        Log::info("Taxa not found, attempting flexible synonym fallback for: {$scientificName}");
        
        try {
            // First, try to find by accepted_scientific_name (reverse lookup)
            $acceptedNameMatch = DB::table('taxas')
                ->where('accepted_scientific_name', $scientificName)
                ->first();
                
            if ($acceptedNameMatch) {
                Log::info("Found taxa by accepted_scientific_name reverse lookup for: {$scientificName}", [
                    'taxa_id' => $acceptedNameMatch->id,
                    'scientific_name' => $acceptedNameMatch->scientific_name,
                    'taxonomic_status' => $acceptedNameMatch->taxonomic_status
                ]);
                
                return [
                    'found' => true,
                    'used_synonym' => true,
                    'taxa_id' => $acceptedNameMatch->id,
                    'synonym_name' => $acceptedNameMatch->scientific_name
                ];
            }
            
            // Extract genus and species from scientific name
            $nameParts = explode(' ', trim($scientificName));
            if (count($nameParts) >= 2) {
                $genus = $nameParts[0];
                $species = $nameParts[1];
                
                // Search for synonym with same genus and species combination
                $synonymQuery = DB::table('taxas')
                    ->where('genus', $genus)
                    ->where('species', $species)
                    ->where('taxon_rank', 'SPECIES')
                    ->first();
                
                if ($synonymQuery) {
                    Log::info("Found flexible synonym fallback for: {$scientificName}", [
                        'synonym_id' => $synonymQuery->id,
                        'synonym_name' => $synonymQuery->scientific_name
                    ]);
                    
                    return [
                        'found' => true,
                        'used_synonym' => true,
                        'taxa_id' => $synonymQuery->id,
                        'synonym_name' => $synonymQuery->scientific_name
                    ];
                }
            }
            
            // Try partial match without author citations - enhanced for better matching
            $nameWithoutAuthor = $scientificName;
            
            // Remove author citations in parentheses
            $nameWithoutAuthor = preg_replace('/\s+\([^)]+\).*$/', '', $nameWithoutAuthor);
            
            // Remove author names (patterns like "Ach.", "Rabenh.", "L.", etc.)
            $nameWithoutAuthor = preg_replace('/\s+[A-Z][a-z]*\.?\s*$/', '', $nameWithoutAuthor);
            $nameWithoutAuthor = preg_replace('/\s+[A-Z][a-z]+\.?\s*$/', '', $nameWithoutAuthor);
            
            // Remove multiple author patterns
            $nameWithoutAuthor = preg_replace('/\s+[A-Z][a-z]*\.\s*&\s*[A-Z][a-z]*\.?\s*$/', '', $nameWithoutAuthor);
            
            $nameWithoutAuthor = trim($nameWithoutAuthor);
            
            if ($nameWithoutAuthor !== $scientificName && strlen($nameWithoutAuthor) > 0) {
                Log::info("Trying partial match without author for: {$scientificName} -> {$nameWithoutAuthor}");
                
                // Try exact match first
                $exactPartialMatch = DB::table('taxas')
                    ->where('scientific_name', $nameWithoutAuthor)
                    ->where('taxon_rank', 'SPECIES')
                    ->first();
                    
                if ($exactPartialMatch) {
                    Log::info("Found exact partial synonym fallback for: {$scientificName}", [
                        'synonym_id' => $exactPartialMatch->id,
                        'synonym_name' => $exactPartialMatch->scientific_name
                    ]);
                    
                    return [
                        'found' => true,
                        'used_synonym' => true,
                        'taxa_id' => $exactPartialMatch->id,
                        'synonym_name' => $exactPartialMatch->scientific_name
                    ];
                }
                
                // Try LIKE match as fallback
                $likePartialMatch = DB::table('taxas')
                    ->where('scientific_name', 'LIKE', $nameWithoutAuthor . '%')
                    ->where('taxon_rank', 'SPECIES')
                    ->first();
                    
                if ($likePartialMatch) {
                    Log::info("Found LIKE partial synonym fallback for: {$scientificName}", [
                        'synonym_id' => $likePartialMatch->id,
                        'synonym_name' => $likePartialMatch->scientific_name
                    ]);
                    
                    return [
                        'found' => true,
                        'used_synonym' => true,
                        'taxa_id' => $likePartialMatch->id,
                        'synonym_name' => $likePartialMatch->scientific_name
                    ];
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error in synonym fallback search:', [
                'error' => $e->getMessage(),
                'scientific_name' => $scientificName
            ]);
        }
        
        return [
            'found' => false,
            'used_synonym' => false,
            'taxa_id' => null,
            'synonym_name' => null
        ];
    }

    private function formatScientificName($name, $rank)
    {
        $parts = explode(' ', $name);
        
        switch ($rank) {
            case 'SUBSPECIES':
                // Format: Genus species subspecies (tanpa subsp.)
                if (count($parts) >= 3) {
                    $genus = $parts[0];
                    $species = $parts[1];
                    $subspecies = '';
                    
                    // Cari subspecies name (skip 'subsp.' atau 'subspecies')
                    for ($i = 2; $i < count($parts); $i++) {
                        if (!in_array(strtolower($parts[$i]), ['subsp.', 'ssp.', 'subspecies'])) {
                            $subspecies = $parts[$i];
                            break;
                        }
                    }
                    
                    return "$genus $species $subspecies";
                }
                break;
                
            case 'VARIETY':
                // Format: Genus species var. variety
                if (count($parts) >= 4) {
                    $genus = $parts[0];
                    $species = $parts[1];
                    $variety = '';
                    
                    // Cari variety name (skip 'var.' atau 'variety')
                    for ($i = 2; $i < count($parts); $i++) {
                        if (!in_array(strtolower($parts[$i]), ['var.', 'variety'])) {
                            $variety = $parts[$i];
                            break;
                        }
                    }
                    
                    return "$genus $species var. $variety";
                }
                break;
                
            case 'FORM':
                // Format: Genus species f. form
                if (count($parts) >= 4) {
                    $genus = $parts[0];
                    $species = $parts[1];
                    $form = end($parts);
                    return "$genus $species f. $form";
                }
                break;
        }
        
        return $name; // Return original if no special formatting needed
    }

    private function detectRankFromName($scientificName)
    {
        // Daftar taksa yang secara khusus diidentifikasi
        $specialTaxa = [
            'CLASS' => ['Teleostei', 'Actinopterygii', 'Mammalia', 'Aves', 'Reptilia', 'Amphibia',
                         'Insecta', 'Arachnida', 'Malacostraca', 'Bivalvia', 'Gastropoda', 'Cephalopoda'],
            'PHYLUM' => ['Chordata', 'Arthropoda', 'Mollusca', 'Echinodermata', 'Cnidaria', 'Porifera'],
            'KINGDOM' => ['Animalia', 'Plantae', 'Fungi', 'Protista', 'Monera'],
            'ORDER' => ['Passeriformes', 'Coleoptera', 'Lepidoptera', 'Diptera', 'Hymenoptera',
                         'Araneae', 'Squamata', 'Testudines', 'Carnivora', 'Primates', 'Rodentia']
        ];
        
        // Cek apakah nama ada dalam daftar khusus
        foreach ($specialTaxa as $rank => $taxaList) {
            if (in_array($scientificName, $taxaList)) {
                return $rank;
            }
        }
        
        // Jika tidak dalam daftar khusus, deteksi berdasarkan jumlah kata
        $nameParts = explode(' ', $scientificName);
        $count = count($nameParts);
        
        // Deteksi pola khusus untuk genus dengan author yang kompleks
        // Contoh: "Malaxis Sol. ex Sw." atau "Genus Author1 ex Author2"
        $hasComplexAuthorPattern = false;
        if ($count >= 3 && in_array('ex', $nameParts)) {
            $firstWord = $nameParts[0];
            
            // Cek jika kata pertama diawali huruf kapital (genus)
            if (preg_match('/^[A-Z][a-z]+$/', $firstWord)) {
                // Cek jika ini pola "Genus Author1 ex Author2"
                $hasComplexAuthorPattern = true;
                Log::info("Detected genus with complex author pattern: {$scientificName}");
            }
        }
        
        // Daftar pola author umum untuk tumbuhan
        $commonAuthorPatterns = [
            'Lindl.', 'Sw.', 'Jungh.', 'Vriese', 'L.', 'Linn.', 'Hook.', 'Miq.', 'Blume', 'Roxb.', 
            'Wall.', 'Thunb.', 'Benth.', 'Merr.', 'Baker', 'DC.', 'Span.', 'Bl.',
            'Burm.f.', 'Korth.', 'J.Smith', 'C.B.Clarke', 'Zoll.', 'R.Br.', 
            'Jack', 'Lam.', 'Gaertn.', 'Steud.', 'Nees', 'C.B.Rob', 'Sm.', 'Pers.',
            'Hassk.', 'Vahl', 'King', 'F.M.Bailey', 'Bailey', 'Hemsl.', 'Mast.', 
            'Pierre', 'Rumph.', 'Ridl.', 'Andrews', 'Kurz', 'Koord.', 'Valeton',
            'Sw.', 'Schltr.', 'Rolfe', 'J.J.Sm.', 'Rchb.f.', 'Pfitzer', 'Ames',
            'Sol.', 'Spreng.', 'Willd.', 'A.Rich.', 'Rchb.', 'Nutt.', 'Muhl.',
            'Kunth', 'Jacq.', 'Griseb.', 'Endl.', 'Crantz', 'Cav.', 'Britton'
        ];
        
        // Pola untuk genus dengan author (misalnya "Coelogyne Lindl.")
        $hasGenusAuthorPattern = false;
        if ($count == 2) {
            $firstWord = $nameParts[0];
            $secondWord = $nameParts[1];
            
            // Cek jika kata pertama diawali huruf kapital (genus) dan kata kedua adalah author
            if (preg_match('/^[A-Z][a-z]+$/', $firstWord)) {
                // Cek jika kata kedua adalah author (diawali huruf kapital dan diakhiri titik atau huruf kapital saja)
                if (preg_match('/^[A-Z][a-z]*\.?$/', $secondWord) || in_array($secondWord, $commonAuthorPatterns)) {
                    $hasGenusAuthorPattern = true;
                    Log::info("Detected genus with author: {$scientificName}");
                }
            }
        }
        
        // Pola spesifik untuk nama spesies dengan author (misalnya "Pinus merkusii Jungh. & de Vriese")
        $hasAuthorPattern = preg_match('/^[A-Z][a-z]+ [a-z]+ [A-Z][a-z.]+(\s+(&|et)\s+[a-zA-Z.]+)*$/', $scientificName);
        $hasTypicalAuthorAbbr = preg_match('/\s(L\.|Linn\.|Blume|Thunb\.|Roxb\.|Wall\.|Mill\.|Hook\.|Benth\.|Miq\.|Willd\.|Jungh\.|Vriese)/', $scientificName);
        
        // Cek terhadap daftar author
        $hasCommonAuthor = false;
        foreach ($commonAuthorPatterns as $author) {
            if (strpos($scientificName, $author) !== false) {
                $hasCommonAuthor = true;
                break;
            }
        }
        
        // Periksa tanda khusus yang biasa muncul di nama spesies tumbuhan dengan author
        $hasAuthorMarkers = strpos($scientificName, ' & ') !== false || 
                            strpos($scientificName, ' et ') !== false ||
                            strpos($scientificName, ' ex ') !== false;
        
        if ($count == 1) {
            // Default untuk satu kata adalah GENUS
            return 'GENUS';
        } else if ($count == 2 && $hasGenusAuthorPattern) {
            // Jika terdeteksi pola genus dengan author, kembalikan GENUS
            return 'GENUS';
        } else if ($hasComplexAuthorPattern) {
            // Jika terdeteksi pola genus dengan author kompleks (ex), kembalikan GENUS
            return 'GENUS';
        } else if ($count == 2) {
            // Dua kata biasanya species
            return 'SPECIES';
        } else if ($count == 3 && (strpos(strtolower($scientificName), 'subsp.') !== false ||
                                 strpos(strtolower($scientificName), 'ssp.') !== false)) {
            return 'SUBSPECIES';
        } else if ($count >= 3 && (strpos(strtolower($scientificName), 'var.') !== false)) {
            return 'VARIETY';
        } else if ($count >= 3 && $this->hasFormIndicator($scientificName)) {
            return 'FORM';
        } else if ($hasAuthorPattern || $hasTypicalAuthorAbbr || $hasCommonAuthor || $hasAuthorMarkers) {
            // Jika ada pola nama species dengan author, ini kemungkinan besar SPECIES, bukan SUBSPECIES
            Log::info("Detected species with author citation: {$scientificName}");
            return 'SPECIES';
        } else if ($count >= 3) {
            // Check if this is a species with author citation in parentheses
            if ($this->hasParentheticalAuthor($scientificName)) {
                Log::info("Detected species with parenthetical author: {$scientificName}");
                return 'SPECIES';
            }
            
            // Cek apakah ini kemungkinan nama species dengan author
            // Kata ketiga biasanya dimulai dengan huruf besar jika itu author
            if (isset($nameParts[2]) && preg_match('/^[A-Z]/', $nameParts[2])) {
                Log::info("Detected probable species with author: {$scientificName}");
                return 'SPECIES';
            }
            
            // Check if this looks like a subspecies (has subsp. indicator or trinomial without author)
            if ($this->looksLikeSubspecies($scientificName, $nameParts)) {
                return 'SUBSPECIES';
            }
            
            // Default to SPECIES for 3+ words if no clear subspecies indicators
            Log::info("Defaulting to SPECIES for multi-word name: {$scientificName}");
            return 'SPECIES';
        }
        
        // Default ke GENUS jika tidak ada yang cocok
        return 'GENUS';
    }

    /**
     * Check if the scientific name has form indicators while avoiding false positives from author abbreviations
     */
    private function hasFormIndicator($scientificName)
    {
        $nameParts = explode(' ', $scientificName);
        
        // Check for explicit form indicators
        if (strpos(strtolower($scientificName), 'forma') !== false) {
            return true;
        }
        
        // Check for 'f.' as a separate word (not part of author abbreviation)
        foreach ($nameParts as $index => $part) {
            if (strtolower($part) === 'f.') {
                // If 'f.' is found as a separate word, it's likely a form indicator
                return true;
            }
            
            // Check if 'f.' appears at the beginning of a word (like 'f.alba')
            if (preg_match('/^f\.[a-z]/i', $part)) {
                return true;
            }
        }
        
        // Additional check: if we have a pattern like "Genus species f. form"
        if (count($nameParts) >= 4) {
            for ($i = 2; $i < count($nameParts) - 1; $i++) {
                if (strtolower($nameParts[$i]) === 'f.' && 
                    preg_match('/^[a-z]/', $nameParts[$i + 1])) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if the scientific name has author citation in parentheses
     */
    private function hasParentheticalAuthor($scientificName)
    {
        // Check for author citations in parentheses like "(S.Moore)" or "(L.) Author"
        if (preg_match('/\([^)]+\)/', $scientificName)) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if the name looks like a subspecies rather than a species with author
     */
    private function looksLikeSubspecies($scientificName, $nameParts)
    {
        // Explicit subspecies indicators
        if (strpos(strtolower($scientificName), 'subsp.') !== false ||
            strpos(strtolower($scientificName), 'ssp.') !== false ||
            strpos(strtolower($scientificName), 'subspecies') !== false) {
            return true;
        }
        
        // If we have exactly 3 words and no author indicators, it might be a subspecies
        if (count($nameParts) === 3) {
            $thirdWord = $nameParts[2];
            
            // If third word doesn't look like an author (no capitals, dots, parentheses)
            if (!preg_match('/[A-Z.]/', $thirdWord) && 
                !preg_match('/\([^)]+\)/', $scientificName)) {
                return true;
            }
        }
        
        return false;
    }

    private function extractBaseScientificName($scientificName)
    {
        // Hapus author dan tahun dalam kurung
        $name = preg_replace('/\([^)]+\)/', '', $scientificName);
        
        // Hapus author dan tahun tanpa kurung (pola: Nama Author, YYYY)
        $name = preg_replace('/\s+[A-Z][a-zé\s]+,?\s+\d{4}/', '', $name);
        
        // Hapus 'subsp.' jika ada
        $name = str_replace(' subsp.', '', $name);
        
        // Bersihkan spasi berlebih
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function getTaxonomyInfo($scientificName)
    {
        try {
            // Menggunakan GBIF API untuk mendapatkan informasi taksonomi
            $response = Http::get('https://api.gbif.org/v1/species/match', [
                'name' => $scientificName,
                'strict' => false
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'data' => [
                        'scientific_name' => $data['scientificName'] ?? $scientificName,
                        'taxon_rank' => strtolower($data['rank'] ?? ''),
                        'kingdom' => $data['kingdom'] ?? '',
                        'phylum' => $data['phylum'] ?? '',
                        'class' => $data['class'] ?? '',
                        'order' => $data['order'] ?? '',
                        'family' => $data['family'] ?? '',
                        'genus' => $data['genus'] ?? '',
                        'species' => $data['species'] ?? ''
                    ]
                ];
            }

            return [
                'success' => false,
                'message' => 'Tidak dapat menemukan informasi taksonomi'
            ];

        } catch (\Exception $e) {
            Log::error('Error getting taxonomy info: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil informasi taksonomi'
            ];
        }
    }

    // Tambahkan endpoint baru untuk mendapatkan informasi taksonomi
    public function getTaxonomy(Request $request)
    {
        $request->validate([
            'scientific_name' => 'required|string'
        ]);

        $result = $this->getTaxonomyInfo($request->scientific_name);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'data' => $result['data']
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message']
        ], 404);
    }

    public function getChecklistDetail($id)
    {
        try {
            $userId = JWTAuth::user()->id;

            $checklist = DB::table('fobi_checklist_taxas')
                ->join('taxa_quality_assessments', 'fobi_checklist_taxas.id', '=', 'taxa_quality_assessments.taxa_id')
                ->join('fobi_users', 'fobi_checklist_taxas.user_id', '=', 'fobi_users.id')
                ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                ->where('fobi_checklist_taxas.id', $id)
                ->select(
                    'fobi_checklist_taxas.*',
                    'taxa_quality_assessments.grade',
                    'fobi_users.uname as observer_name',
                    'taxas.iucn_red_list_category',
                    'fobi_checklist_taxas.agreement_count'
                )
                ->first();

            if (!$checklist) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checklist tidak ditemukan'
                ], 404);
            }

            // Ambil semua media terkait
            $medias = DB::table('fobi_checklist_media')
                ->where('checklist_id', $id)
                ->select(
                    'id',
                    DB::raw("CASE
                        WHEN media_type = 'photo' THEN 'image'
                        WHEN media_type = 'audio' THEN 'audio'
                        ELSE media_type
                    END as type"),
                    DB::raw("CONCAT('" . asset('storage/') . "/', file_path) as url"),
                    DB::raw("CASE
                        WHEN media_type = 'audio'
                        THEN CONCAT('" . asset('storage/') . "/', REPLACE(file_path, SUBSTRING_INDEX(file_path, '.', -1), 'png'))
                        ELSE NULL
                    END as spectrogramUrl")
                )
                ->get();

            // Ambil identifikasi dengan informasi tambahan
            $identifications = DB::table('taxa_identifications as ti')
                ->join('fobi_users as u', 'ti.user_id', '=', 'u.id')
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->where('ti.checklist_id', $id)
                ->select(
                    'ti.*',
                    'u.uname',
                    't.scientific_name',
                    't.taxon_rank as identification_level',
                    'u.uname as identifier_name',
                    'u.created_at as identifier_joined_date',
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE user_id = u.id) as identifier_identification_count'),
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications AS ti2
                        WHERE ti2.agrees_with_id = ti.id
                        AND ti2.is_agreed = true) as agreement_count'),
                    DB::raw('(SELECT COUNT(*) > 0 FROM taxa_identifications AS ti2
                        WHERE ti2.agrees_with_id = ti.id
                        AND ti2.user_id = ' . $userId . '
                        AND ti2.is_agreed = true) as user_agreed'),
                    DB::raw('(SELECT COUNT(*) > 0 FROM taxa_identifications AS ti2
                        WHERE ti2.agrees_with_id = ti.id
                        AND ti2.user_id = ' . $userId . '
                        AND ti2.is_agreed = false) as user_disagreed'),
                    DB::raw("CASE WHEN ti.photo_path IS NOT NULL
                        THEN CONCAT('" . asset('storage') . "/', ti.photo_path)
                        ELSE NULL END as photo_url")
                )
                ->orderBy('ti.is_first', 'desc')
                ->orderBy('ti.created_at', 'desc')
                ->get();

            // Ambil agreements untuk checklist
            $agreements = DB::table('taxa_identifications as ti')
                ->join('fobi_users as u', 'ti.user_id', '=', 'u.id')
                ->where('ti.checklist_id', $id)
                ->where('ti.is_agreed', true)
                ->select(
                    'u.uname as user_name',
                    'ti.created_at as agreed_at',
                    'u.created_at as user_joined_date',
                    DB::raw('(SELECT COUNT(*) FROM taxa_identifications WHERE user_id = u.id) as total_identifications')
                )
                ->get();

            // Ambil verifikasi lokasi dan status liar
            $locationVerifications = DB::table('taxa_location_verifications')
                ->where('checklist_id', $id)
                ->get();

            $wildStatusVotes = DB::table('taxa_wild_status_votes')
                ->where('checklist_id', $id)
                ->get();

            // Tambahkan medias dan agreements ke dalam checklist
            $checklist->medias = $medias;
            $checklist->agreements = $agreements;

            return response()->json([
                'success' => true,
                'data' => [
                    'checklist' => $checklist,
                    'identifications' => $identifications,
                    'location_verifications' => $locationVerifications,
                    'wild_status_votes' => $wildStatusVotes,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching checklist detail: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }
    public function addIdentification(Request $request, $id)
    {
        try {
            $request->validate([
                'taxon_id' => 'required|exists:taxas,id',
                'comment' => 'nullable|string|max:500',
                'photo' => 'nullable|image|max:5120', // Max 5MB
                'identification_level' => 'required|string'
            ]);

            DB::beginTransaction();

            $user = JWTAuth::user();
            $photoPath = null;

            // Proses upload foto jika ada
            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');
                $photoPath = $photo->store('identification-photos', 'public');
            }

            // Simpan identifikasi
            $identificationId = DB::table('taxa_identifications')->insertGetId([
                'checklist_id' => $id,
                'user_id' => $user->id,
                'taxon_id' => $request->taxon_id,
                'identification_level' => $request->identification_level,
                'comment' => $request->comment,
                'photo_path' => $photoPath,
                'is_first' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Update quality assessment
            $this->updateQualityAssessment($id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Identifikasi berhasil ditambahkan',
                'data' => [
                    'id' => $identificationId,
                    'photo_url' => $photoPath ? asset('storage/' . $photoPath) : null
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menambahkan identifikasi'
            ], 500);
        }
    }

    // Tambahkan method untuk mengambil identifikasi dengan foto
    private function getIdentificationsWithPhotos($checklistId)
    {
        return DB::table('taxa_identifications as ti')
            ->join('fobi_users as u', 'ti.user_id', '=', 'u.id')
            ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
            ->where('ti.checklist_id', $checklistId)
            ->select(
                'ti.*',
                'u.uname as identifier_name',
                't.scientific_name',
                DB::raw("CASE WHEN ti.photo_path IS NOT NULL
                    THEN CONCAT('" . asset('storage') . "/', ti.photo_path)
                    ELSE NULL END as photo_url")
            )
            ->orderBy('ti.created_at', 'desc')
            ->get();
    }
    public function withdrawIdentification($checklistId, $identificationId)
    {
        try {
            DB::beginTransaction();

            // Tarik identifikasi
            DB::table('taxa_identifications')
                ->where('id', $identificationId)
                ->update(['is_withdrawn' => true]);

            // Hapus semua persetujuan terkait
            DB::table('taxa_identifications')
                ->where('agrees_with_id', $identificationId)
                ->delete();

            // Reset community_id_level dan grade ke default
            DB::table('taxa_quality_assessments')
                ->where('taxa_id', $checklistId)
                ->update([
                    'community_id_level' => null,
                    'grade' => 'needs ID'
                ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Identifikasi berhasil ditarik dan pengaturan direset'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in withdrawIdentification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menarik identifikasi'
            ], 500);
        }
    }
        private function updateChecklistTaxon($checklistId)
    {
        try {
            // Ambil identifikasi dengan persetujuan terbanyak
            $mostAgreedIdentification = DB::table('taxa_identifications as ti')
                ->select(
                    'ti.taxon_id',
                    't.scientific_name',
                    't.iucn_red_list_category',
                    't.class',
                    't.order',
                    't.family',
                    't.genus',
                    't.species',
                    DB::raw('COUNT(ti2.id) as agreement_count')
                )
                ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                ->leftJoin('taxa_identifications as ti2', function($join) {
                    $join->on('ti.id', '=', 'ti2.agrees_with_id')
                        ->where('ti2.is_agreed', '=', true);
                })
                ->where('ti.checklist_id', $checklistId)
                ->where('ti.is_first', true) // Hanya cek persetujuan untuk identifikasi pertama
                ->groupBy('ti.taxon_id', 't.scientific_name', 't.iucn_red_list_category',
                         't.class', 't.order', 't.family', 't.genus', 't.species')
                ->first();

                if ($mostAgreedIdentification) {
                    // Update checklist dengan taxa yang disetujui
                    $updateData = [
                        'taxa_id' => $mostAgreedIdentification->taxon_id,
                        'scientific_name' => $mostAgreedIdentification->scientific_name,
                        'class' => $mostAgreedIdentification->class,
                        'order' => $mostAgreedIdentification->order,
                        'family' => $mostAgreedIdentification->family,
                        'genus' => $mostAgreedIdentification->genus,
                        'species' => $mostAgreedIdentification->species,
                        'agreement_count' => $mostAgreedIdentification->agreement_count
                    ];

                    // Cek apakah memenuhi kriteria research grade
                    $assessment = TaxaQualityAssessment::where('taxa_id', $checklistId)->first();

                    if ($assessment && $assessment->grade === 'research grade') {
                        $updateData['iucn_status'] = $mostAgreedIdentification->iucn_red_list_category;
                    } else {
                        $updateData['iucn_status'] = null; // Reset jika bukan research grade
                    }

                    DB::table('fobi_checklist_taxas')
                        ->where('id', $checklistId)
                        ->update($updateData);
                // Log perubahan
                Log::info('Checklist updated:', [
                    'checklist_id' => $checklistId,
                    'new_taxa_id' => $mostAgreedIdentification->taxon_id,
                    'agreement_count' => $mostAgreedIdentification->agreement_count,
                    'is_research_grade' => $assessment ? $assessment->grade === 'research grade' : false
                ]);
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error updating checklist taxon: ' . $e->getMessage());
            throw $e;
        }
    }

    private function updateCommunityConsensus($checklistId)
    {
        $mostAgreedIdentification = DB::table('taxa_identifications')
            ->select('taxon_id', DB::raw('COUNT(*) as agreement_count'))
            ->where('checklist_id', $checklistId)
            ->where('is_agreed', true)
            ->groupBy('taxon_id')
            ->orderBy('agreement_count', 'desc')
            ->first();

        $updateData = [
            'updated_at' => now()
        ];

        if ($mostAgreedIdentification && $mostAgreedIdentification->agreement_count >= 2) {
            $updateData['taxa_id'] = $mostAgreedIdentification->taxon_id;
            $updateData['agreement_count'] = $mostAgreedIdentification->agreement_count;
        } else {
            // Reset jika tidak ada yang memenuhi syarat
            $updateData['agreement_count'] = 0;
        }

        DB::table('fobi_checklist_taxas')
            ->where('id', $checklistId)
            ->update($updateData);
    }

    private function evaluateAndUpdateGrade($assessment, $agreementCount)
    {
        try {
            // Ambil data checklist
            $checklist = FobiChecklistTaxa::findOrFail($assessment->taxa_id);
            $oldGrade = $assessment->grade;

            // Evaluasi grade berdasarkan kriteria
            if ($this->meetsResearchGradeCriteria($assessment, $agreementCount)) {
                $assessment->grade = 'research grade';

                // Jika baru mencapai research grade, update IUCN status
                if ($oldGrade !== 'research grade') {
                    // Ambil IUCN status dari taxa yang disetujui
                    $approvedTaxa = DB::table('taxa_identifications as ti')
                        ->join('taxas as t', 'ti.taxon_id', '=', 't.id')
                        ->where('ti.checklist_id', $assessment->taxa_id)
                        ->where('ti.is_agreed', true)
                        ->select('t.iucn_red_list_category')
                        ->first();

                    if ($approvedTaxa && $approvedTaxa->iucn_red_list_category) {
                        // Update IUCN status di checklist
                        DB::table('fobi_checklist_taxas')
                            ->where('id', $assessment->taxa_id)
                            ->update([
                                'iucn_status' => $approvedTaxa->iucn_red_list_category
                            ]);
                    }
                }
            }
            else if ($this->meetsNeedsIdCriteria($assessment)) {
                $assessment->grade = 'needs ID';
            }
            else {
                $assessment->grade = 'casual';
            }

            $assessment->save();

            Log::info('Grade evaluation result:', [
                'taxa_id' => $assessment->taxa_id,
                'old_grade' => $oldGrade,
                'new_grade' => $assessment->grade,
                'agreement_count' => $agreementCount,
                'iucn_status_updated' => $oldGrade !== 'research grade' && $assessment->grade === 'research grade'
            ]);

        } catch (\Exception $e) {
            Log::error('Error in evaluateAndUpdateGrade:', [
                'error' => $e->getMessage(),
                'taxa_id' => $assessment->taxa_id ?? null
            ]);
            throw $e;
        }
    }
    // Tambahkan endpoint baru untuk menangani persetujuan identifikasi
    public function agreeWithIdentification($checklistId, $identificationId)
    {
        try {
            DB::beginTransaction();

            $user = JWTAuth::user();

            // Cek apakah sudah pernah setuju
            $existingAgreement = DB::table('taxa_identifications')
                ->where('checklist_id', $checklistId)
                ->where('user_id', $user->id)
                ->where('agrees_with_id', $identificationId)
                ->first();

            if ($existingAgreement) {
                throw new \Exception('Anda sudah menyetujui identifikasi ini');
            }

            // Ambil identifikasi yang disetujui
            $agreedIdentification = DB::table('taxa_identifications as ti')
                ->join('taxas as t', 't.id', '=', 'ti.taxon_id')
                ->join('fobi_users as u', 'u.id', '=', 'ti.user_id')
                ->where('ti.id', $identificationId)
                ->select(
                    'ti.*',
                    't.scientific_name',
                    't.iucn_red_list_category',
                    'u.uname as identifier_name'
                )
                ->first();

            // Simpan persetujuan
            $agreement = DB::table('taxa_identifications')->insert([
                'checklist_id' => $checklistId,
                'user_id' => $user->id,
                'agrees_with_id' => $identificationId,
                'taxon_id' => $agreedIdentification->taxon_id,
                'identification_level' => $agreedIdentification->identification_level,
                'is_agreed' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // Hitung jumlah persetujuan untuk identifikasi ini
            $agreementCount = DB::table('taxa_identifications')
                ->where('agrees_with_id', $identificationId)
                ->where('is_agreed', true)
                ->count();

            // Jika persetujuan mencapai 2 atau lebih, update checklist dan assessment
            if ($agreementCount >= 2) {
                // Ambil data checklist sebelumnya
                $currentChecklist = DB::table('fobi_checklist_taxas')
                    ->where('id', $checklistId)
                    ->first();

                // Simpan perubahan ke history
                DB::table('taxa_identification_histories')->insert([
                    'checklist_id' => $checklistId,
                    'taxa_id' => $agreedIdentification->taxon_id,
                    'user_id' => $user->id,
                    'action_type' => 'change',
                    'scientific_name' => $agreedIdentification->scientific_name,
                    'previous_name' => $currentChecklist->scientific_name,
                    'reason' => 'Persetujuan komunitas',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // Update checklist
                DB::table('fobi_checklist_taxas')
                    ->where('id', $checklistId)
                    ->update([
                        'taxa_id' => $agreedIdentification->taxon_id,
                        'scientific_name' => $agreedIdentification->scientific_name,
                        'agreement_count' => $agreementCount,
                        'updated_at' => now()
                    ]);

                // Update quality assessment dengan community_id_level
                $assessment = TaxaQualityAssessment::firstOrCreate(
                    ['taxa_id' => $checklistId],
                    ['grade' => 'needs ID']
                );

                $assessment->community_id_level = $agreedIdentification->identification_level;

                // Evaluasi dan update grade
                $this->evaluateAndUpdateGrade($assessment, $agreementCount);
            } else {
                // Jika belum mencapai 2 persetujuan
                $assessment = TaxaQualityAssessment::firstOrCreate(
                    ['taxa_id' => $checklistId],
                    ['grade' => 'needs ID']
                );

                // Tetap update community_id_level jika ini persetujuan pertama
                if ($agreementCount === 1) {
                    $assessment->community_id_level = $agreedIdentification->identification_level;
                }

                $assessment->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'agreement_count' => $agreementCount,
                    'user_agreed' => true,
                    'checklist' => [
                        'taxa_id' => $agreedIdentification->taxon_id,
                        'scientific_name' => $agreedIdentification->scientific_name,
                        'iucn_status' => $agreedIdentification->iucn_red_list_category,
                        'agreement_count' => $agreementCount
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in agreeWithIdentification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

// Perlu diperbarui logika evaluasi grade
private function evaluateGrade($checklistId)
{
    $checklist = DB::table('fobi_checklist_taxas')
        ->where('id', $checklistId)
        ->first();

    $agreementCount = DB::table('taxa_identifications')
        ->where('checklist_id', $checklistId)
        ->where('is_agreed', true)
        ->count();

    // Update checklist
    DB::table('fobi_checklist_taxas')
        ->where('id', $checklistId)
        ->update([
            'agreement_count' => $agreementCount
        ]);

    // Update assessment
    $assessment = TaxaQualityAssessment::firstOrCreate(
        ['taxa_id' => $checklistId],
        ['grade' => 'needs ID']
    );

    if ($agreementCount < 2) {
        $assessment->community_id_level = null;
        $assessment->grade = 'needs ID';
    }

    $assessment->save();

    return $assessment->grade;
}
private function meetsResearchGradeCriteria($assessment, $agreementCount)
{
    return $assessment->has_date &&
           $assessment->has_location &&
           $assessment->has_media &&
           $agreementCount >= 2 &&
           $assessment->is_wild === true &&
           $assessment->location_accurate === true &&
           $assessment->recent_evidence === true &&
           $assessment->related_evidence === true;
}
private function meetsNeedsIdCriteria($assessment, $agreementCount, $totalIdentifications)
{
    $basicCriteria = $assessment->has_date &&
                     $assessment->has_location &&
                     $assessment->has_media;

    $identificationCriteria = $totalIdentifications < 2 || // Belum cukup identifikasi forum
                             ($agreementCount >= 2 && ($agreementCount / $totalIdentifications) < (2/3)) || // Dikonfirmasi >2 tapi kurang dari 2/3 forum
                             $agreementCount == 0; // Belum ada yang setuju

    return $basicCriteria && $identificationCriteria;
}
    private function meetsLowQualityIdCriteria($assessment, $agreementCount, $totalIdentifications)
{
    $basicCriteria = $assessment->has_date &&
                     $assessment->has_location &&
                     $assessment->has_media;

    $identificationCriteria = $totalIdentifications >= 2 && // Minimal 2 identifikasi forum
                             $agreementCount == 1; // Tepat 1 persetujuan

    return $basicCriteria && $identificationCriteria;
}

    private function determineCommunityLevel($checklistId)
    {
        $identifications = DB::table('taxa_identifications')
            ->where('checklist_id', $checklistId)
            ->select('identification_level', DB::raw('count(*) as count'))
            ->groupBy('identification_level')
            ->orderBy('count', 'desc')
            ->get();

        // Minimal 2 identifikasi yang sama untuk konsensus
        foreach ($identifications as $identification) {
            if ($identification->count >= 2) {
                return strtolower($identification->identification_level);
            }
        }

        // Jika tidak ada konsensus, ambil level tertinggi
        $firstIdentification = DB::table('taxa_identifications')
            ->where('checklist_id', $checklistId)
            ->orderBy('created_at', 'desc')
            ->first();

        return $firstIdentification ? strtolower($firstIdentification->identification_level) : null;
    }

    public function verifyLocation(Request $request, $id)
    {
        $request->validate([
            'is_accurate' => 'required|boolean',
            'comment' => 'nullable|string|max:500'
        ]);

        try {
            $userId = JWTAuth::user()->id;
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'Pengguna tidak terautentikasi'], 401);
            }

            DB::table('taxa_location_verifications')->insert([
                'checklist_id' => $id,
                'user_id' => $userId,
                'is_accurate' => $request->is_accurate,
                'comment' => $request->comment,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json(['success' => true, 'message' => 'Verifikasi lokasi berhasil ditambahkan']);
        } catch (\Exception $e) {
            Log::error('Error verifying location: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan'], 500);
        }
    }

    public function voteWildStatus(Request $request, $id)
    {
        $request->validate([
            'is_wild' => 'required|boolean',
            'comment' => 'nullable|string|max:500'
        ]);

        try {
            $userId = JWTAuth::user()->id;
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'Pengguna tidak terautentikasi'], 401);
            }

            DB::table('taxa_wild_status_votes')->insert([
                'checklist_id' => $id,
                'user_id' => $userId,
                'is_wild' => $request->is_wild,
                'comment' => $request->comment,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json(['success' => true, 'message' => 'Vote status liar berhasil ditambahkan']);
        } catch (\Exception $e) {
            Log::error('Error voting wild status: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan'], 500);
        }
    }

    public function searchTaxa(Request $request)
    {
        try {
            $query = $request->get('q');
            $page = $request->get('page', 1);
            $perPage = 5;

            if (strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => []
                ])->header('Content-Type', 'application/json');
            }

            // Cari di database lokal dengan pagination
            $localResults = DB::table('taxas')
                ->where('scientific_name', 'LIKE', "%{$query}%")
                ->select('id', 'scientific_name', 'kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species', 'taxon_rank') // Tambahkan id
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            if ($localResults->isNotEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => $localResults,
                    'page' => $page
                ])->header('Content-Type', 'application/json');
            }

            // Jika tidak ada di database lokal, gunakan GBIF API dan simpan ke database
            $response = Http::get('https://api.gbif.org/v1/species/suggest', [
                'q' => $query,
                'offset' => ($page - 1) * $perPage,
                'limit' => $perPage
            ]);

            if ($response->successful()) {
                $suggestions = collect($response->json())->map(function ($item) {
                    // Simpan ke database dan dapatkan ID
                    $taxon = DB::table('taxas')->updateOrInsert(
                        ['scientific_name' => $item['scientificName']],
                        [
                            'kingdom' => $item['kingdom'] ?? '',
                            'phylum' => $item['phylum'] ?? '',
                            'class' => $item['class'] ?? '',
                            'order' => $item['order'] ?? '',
                            'family' => $item['family'] ?? '',
                            'genus' => $item['genus'] ?? '',
                            'species' => $item['species'] ?? '',
                            'taxon_rank' => strtolower($item['rank'] ?? ''),
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    );

                    // Ambil ID taxon yang baru saja disimpan/diupdate
                    $savedTaxon = DB::table('taxas')
                        ->where('scientific_name', $item['scientificName'])
                        ->first();

                    return [
                        'id' => $savedTaxon->id,
                        'scientific_name' => $item['scientificName'] ?? '',
                        'kingdom' => $item['kingdom'] ?? '',
                        'phylum' => $item['phylum'] ?? '',
                        'class' => $item['class'] ?? '',
                        'order' => $item['order'] ?? '',
                        'family' => $item['family'] ?? '',
                        'genus' => $item['genus'] ?? '',
                        'species' => $item['species'] ?? '',
                        'taxon_rank' => strtolower($item['rank'] ?? '')
                    ];
                });

                return response()->json([
                    'success' => true,
                    'data' => $suggestions,
                    'page' => $page
                ])->header('Content-Type', 'application/json');
            }

            return response()->json([
                'success' => true,
                'data' => []
            ])->header('Content-Type', 'application/json');

        } catch (\Exception $e) {
            Log::error('Error searching taxa: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mencari taksonomi'
            ], 500)->header('Content-Type', 'application/json');
        }
    }
        public function addComment(Request $request, $id)
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $userId = JWTAuth::user()->id;
        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Pengguna tidak terautentikasi'], 401);
        }

        try {
            DB::table('checklist_comments')->insert([
                'checklist_id' => $id,
                'user_id' => $userId,
                'comment' => $request->comment,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json(['success' => true, 'message' => 'Komentar berhasil ditambahkan']);
        } catch (\Exception $e) {
            Log::error('Error adding comment: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan'], 500);
        }
    }
    public function rateChecklist(Request $request, $id)
    {
        $request->validate([
            'grade' => 'required|in:research grade,needs ID,casual',
        ]);

        try {
            $userId = JWTAuth::user()->id;
            if (!$userId) {
                return response()->json(['success' => false, 'message' => 'Pengguna tidak terautentikasi'], 401);
            }

            $assessment = TaxaQualityAssessment::where('taxa_id', $id)->first();
            if (!$assessment) {
                return response()->json(['success' => false, 'message' => 'Penilaian tidak ditemukan'], 404);
            }

            $assessment->update(['grade' => $request->input('grade')]);

            return response()->json(['success' => true, 'message' => 'Penilaian berhasil diperbarui']);
        } catch (\Exception $e) {
            Log::error('Error rating checklist: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan'], 500);
        }
    }
    public function getComments($id)
    {
        try {
            $comments = DB::table('checklist_comments')
                ->where('checklist_id', $id)
                ->join('fobi_users', 'checklist_comments.user_id', '=', 'fobi_users.id')
                ->select('checklist_comments.*', 'fobi_users.uname as user_name')
                ->get();

            return response()->json(['success' => true, 'data' => $comments]);
        } catch (\Exception $e) {
            Log::error('Error fetching comments: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan'], 500);
        }
    }


    public function assessQuality($id)
    {
        try {
            // Cek checklist dengan id yang diberikan
            $checklist = FobiChecklistTaxa::findOrFail($id);

            // Hitung jumlah persetujuan
            $agreementCount = DB::table('taxa_identifications')
                ->where('checklist_id', $id)
                ->where('is_agreed', true)
                ->count();

            // Ambil atau buat quality assessment
            $assessment = TaxaQualityAssessment::firstOrNew([
                'taxa_id' => $id  // Gunakan checklist id sebagai taxa_id
            ]);

            // Set nilai default berdasarkan data checklist
            if (!$assessment->exists) {
                $assessment->fill([
                    'has_date' => !empty($checklist->created_at),
                    'has_location' => !empty($checklist->latitude) && !empty($checklist->longitude),
                    'has_media' => DB::table('fobi_checklist_media')->where('checklist_id', $id)->exists(),
                    'is_wild' => true,
                    'location_accurate' => true,
                    'recent_evidence' => true,
                    'related_evidence' => true,
                    'agreement_count' => $agreementCount
                ]);
            }

            // Tentukan grade berdasarkan jumlah persetujuan
            if ($agreementCount >= 2) {
                $assessment->grade = 'research grade';
            } else if ($agreementCount == 1) {
                $assessment->grade = 'low quality ID';
            } else {
                $assessment->grade = 'needs ID';
            }

            $assessment->save();

            return response()->json([
                'success' => true,
                'data' => $assessment
            ]);

        } catch (\Exception $e) {
            Log::error('Error assessing quality: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan'
            ], 500);
        }
    }

    // Hapus fungsi setDefaultAssessment karena sudah diintegrasikan ke dalam assessQuality

    private function determineGrade($checklistId)
    {
        try {
            // Ambil data checklist
            $checklist = FobiChecklistTaxa::findOrFail($checklistId);

            // Hitung jumlah identifikasi
            $identificationCount = DB::table('taxa_identifications')
                ->where('checklist_id', $checklistId)
                ->count();

            // Ambil assessment yang ada
            $assessment = TaxaQualityAssessment::where('taxa_id', $checklistId)->first();

            if (!$assessment) {
                return 'needs ID';
            }

            // Tentukan grade
            if ($this->meetsResearchGradeCriteria($assessment, $identificationCount)) {
                return 'research grade';
            } else if ($this->meetsNeedsIdCriteria($assessment)) {
                return 'needs ID';
            }
            return 'casual';

        } catch (\Exception $e) {
            Log::error('Error determining grade: ' . $e->getMessage(), [
                'checklist_id' => $checklistId
            ]);
            return 'needs ID';
        }
    }
    public function updateQualityAssessment($checklistId)
    {
        try {
            // Ambil data checklist
            $checklist = FobiChecklistTaxa::findOrFail($checklistId);

            // Hitung jumlah identifikasi dan persetujuan
            $totalIdentifications = DB::table('taxa_identifications')
                ->where('checklist_id', $checklistId)
                ->count();

            $agreementCount = DB::table('taxa_identifications')
                ->where('checklist_id', $checklistId)
                ->where('is_agreed', true)
                ->count();

            // Ambil atau buat assessment
            $assessment = TaxaQualityAssessment::firstOrCreate(
                ['taxa_id' => $checklistId],
                [
                    'grade' => 'needs ID',
                    'has_date' => true,
                    'has_location' => !empty($checklist->latitude) && !empty($checklist->longitude),
                    'has_media' => DB::table('fobi_checklist_media')->where('checklist_id', $checklistId)->exists(),
                    'is_wild' => true,
                    'location_accurate' => true,
                    'recent_evidence' => true,
                    'related_evidence' => true
                ]
            );

            // Evaluasi grade berdasarkan kriteria
            if ($this->meetsResearchGradeCriteria($assessment, $agreementCount)) {
                $assessment->grade = 'research grade';
            } else if ($this->meetsLowQualityIdCriteria($assessment, $agreementCount, $totalIdentifications)) {
                $assessment->grade = 'low quality ID';
            } else if ($this->meetsNeedsIdCriteria($assessment, $agreementCount, $totalIdentifications)) {
                $assessment->grade = 'needs ID';
            } else {
                $assessment->grade = 'casual';
            }

            $assessment->save();

            return $assessment;

        } catch (\Exception $e) {
            Log::error('Error in updateQualityAssessment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function updateImprovementStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'can_be_improved' => 'required|boolean'
            ]);

            $checklist = FobiChecklistTaxa::findOrFail($id);

            // Hitung jumlah identifikasi dan persetujuan
            $totalIdentifications = DB::table('taxa_identifications')
                ->where('checklist_id', $id)
                ->count();

            $agreementCount = DB::table('taxa_identifications')
                ->where('checklist_id', $id)
                ->where('is_agreed', true)
                ->count();

            // Ambil atau buat assessment
            $assessment = TaxaQualityAssessment::firstOrCreate(
                ['taxa_id' => $id],
                [
                    'grade' => 'needs ID',
                    'has_date' => true,
                    'has_location' => !empty($checklist->latitude) && !empty($checklist->longitude),
                    'has_media' => DB::table('fobi_checklist_media')->where('checklist_id', $id)->exists(),
                    'is_wild' => true,
                    'location_accurate' => true,
                    'recent_evidence' => true,
                    'related_evidence' => true,
                    'can_be_improved' => null // default value
                ]
            );

            $assessment->can_be_improved = $request->can_be_improved;

            // Evaluasi grade berdasarkan can_be_improved dan kriteria lainnya
            if ($request->can_be_improved) {
                if ($this->meetsNeedsIdCriteria($assessment, $agreementCount, $totalIdentifications)) {
                    $assessment->grade = 'needs ID';
                }
            } else {
                if ($this->meetsResearchGradeCriteria($assessment, $agreementCount, $totalIdentifications)) {
                    $assessment->grade = 'research grade';
                } else if ($this->meetsLowQualityIdCriteria($assessment, $agreementCount, $totalIdentifications)) {
                    $assessment->grade = 'low quality ID';
                }
            }

            $assessment->save();

            return response()->json([
                'success' => true,
                'data' => $assessment,
                'message' => 'Status berhasil diperbarui'
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating improvement status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui status'
            ], 500);
        }
    }
        private function meetsAllCriteria($assessment, $checklistId)
    {
        // Cek identifikasi menggunakan checklist id
        $identificationCount = DB::table('taxa_identifications')
            ->where('checklist_id', $checklistId)
            ->count();

        $latestIdentification = DB::table('taxa_identifications')
            ->where('checklist_id', $checklistId)
            ->orderBy('created_at', 'desc')
            ->first();

        // Periksa community_id_level dari assessment
        $isSpeciesLevel = $assessment->community_id_level === 'species' || $assessment->community_id_level === 'subspecies' || $assessment->community_id_level === 'variety';

        return $assessment->has_date &&
               $assessment->has_location &&
               $assessment->has_media &&
               $assessment->is_wild &&
               $assessment->location_accurate &&
               $assessment->recent_evidence &&
               $assessment->related_evidence &&
               $isSpeciesLevel &&
               $identificationCount >= 2;
    }

    public function getRelatedLocations($taxaId)
    {
        try {
            // Ambil semua checklist dengan taxa_id yang sama
            $relatedLocations = DB::table('fobi_checklist_taxas as fct')
                ->join('taxa_quality_assessments as tqa', 'fct.id', '=', 'tqa.taxa_id')
                ->where('fct.taxa_id', $taxaId)
                ->select(
                    'fct.id',
                    'fct.latitude',
                    'fct.longitude',
                    'fct.scientific_name',
                    'fct.created_at',
                    'tqa.grade'
                )
                ->get();

            // Format response
            $formattedLocations = $relatedLocations->map(function($location) {
                return [
                    'id' => $location->id,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'scientific_name' => $location->scientific_name,
                    'created_at' => $location->created_at,
                    'grade' => $location->grade
                ];
            });

            return response()->json($formattedLocations);

        } catch (\Exception $e) {
            Log::error('Error getting related locations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil lokasi terkait'
            ], 500);
        }
    }

    public function cancelAgreement($checklistId, $identificationId)
    {
        try {
            DB::beginTransaction();

            $user = JWTAuth::user();

            // Hapus sub-identifikasi (persetujuan)
            $deleted = DB::table('taxa_identifications')
                ->where('checklist_id', $checklistId)
                ->where('user_id', $user->id)
                ->where('agrees_with_id', $identificationId)
                ->where('is_agreed', true)
                ->delete();

            if (!$deleted) {
                throw new \Exception('Persetujuan tidak ditemukan');
            }

            // Update jumlah persetujuan
            $agreementCount = DB::table('taxa_identifications')
                ->where('agrees_with_id', $identificationId)
                ->where('is_agreed', true)
                ->count();

            // Update agreement_count di fobi_checklist_taxas
            DB::table('fobi_checklist_taxas')
                ->where('id', $checklistId)
                ->update([
                    'agreement_count' => $agreementCount
                ]);

            // Update assessment jika agreement count < 2
            if ($agreementCount < 2) {
                // Update checklist agreement count
                DB::table('fobi_checklist_taxas')
                    ->where('id', $checklistId)
                    ->update([
                        'agreement_count' => $agreementCount
                    ]);

                // Update assessment
                $assessment = TaxaQualityAssessment::where('taxa_id', $checklistId)->first();
                if ($assessment) {
                    $assessment->grade = 'needs ID';
                    $assessment->community_id_level = null; // Reset level identifikasi komunitas
                    $assessment->save();
                }
            }
            // Hanya update community consensus
            $this->updateCommunityConsensus($checklistId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Persetujuan berhasil dibatalkan',
                'data' => [
                    'agreement_count' => $agreementCount,
                    'grade' => $agreementCount < 2 ? 'needs ID' : 'research grade'
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in cancelAgreement: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    // Tambahkan method untuk menolak identifikasi
    public function disagreeWithIdentification(Request $request, $checklistId, $identificationId)
    {
        $request->validate([
            'taxon_id' => 'nullable|exists:taxas,id',
            'identification_level' => 'required|string',
            'photo' => 'nullable|image|max:2048', // Validasi untuk foto
        ]);

        try {
            DB::beginTransaction();

            $user = JWTAuth::user();

            // Simpan foto jika ada
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('identification_photos', 'public');
            }

            // Simpan penolakan identifikasi
            DB::table('taxa_identifications')->insert([
                'checklist_id' => $checklistId,
                'user_id' => $user->id,
                'taxon_id' => $request->taxon_id,
                'identification_level' => $request->identification_level,
                'comment' => $request->comment,
                'photo_path' => $photoPath,
                'is_agreed' => false,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Penolakan identifikasi berhasil disimpan'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error disagreeing with identification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat menolak identifikasi'
            ], 500);
        }
    }
    public function getObservations(Request $request)
    {
        try {
            // Log semua parameter request untuk debugging
            Log::info('Request parameters:', $request->all());
            
            // Definisikan nilai ENUM yang valid
            $validGrades = ['research grade', 'needs id', 'low quality id', 'confirmed id', 'casual'];

            // Validasi request
            $request->validate([
                'grade' => 'nullable|array',
                'grade.*' => ['nullable', Rule::in($validGrades)],
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'data_source' => 'nullable|array',
                'data_source.*' => 'nullable|string',
                'has_media' => 'nullable|boolean',
                'media_type' => 'nullable|string|in:photo,audio',
                'search' => 'nullable|string',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'radius' => 'nullable|numeric|min:1',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            $perPage = $request->input('per_page', 100);

// Step 1: Ambil ID observations dengan filter dasar
$baseQuery = DB::table('fobi_checklist_taxas')
->select('fobi_checklist_taxas.id')
->join('taxa_quality_assessments', 'fobi_checklist_taxas.id', '=', 'taxa_quality_assessments.taxa_id');

// Terapkan filter dasar dan pengurutan
$baseQuery->orderBy('fobi_checklist_taxas.created_at', 'desc'); // Tambahkan pengurutan di sini

if ($request->has('search')) {
$search = $request->search;
$baseQuery->where(function($q) use ($search) {
    $q->where('fobi_checklist_taxas.scientific_name', 'like', "%{$search}%")
      ->orWhere('fobi_checklist_taxas.genus', 'like', "%{$search}%")
      ->orWhere('fobi_checklist_taxas.species', 'like', "%{$search}%")
      ->orWhere('fobi_checklist_taxas.family', 'like', "%{$search}%");
});
}

if ($request->has('grade') && is_array($request->grade)) {
$grades = array_map('strtolower', $request->grade);
$baseQuery->whereIn(DB::raw('LOWER(taxa_quality_assessments.grade)'), $grades);
}

// Filter lokasi dan tanggal
if ($request->has('latitude') && $request->has('longitude')) {
$lat = $request->latitude;
$lng = $request->longitude;
$radius = $request->radius ?? 10;

$baseQuery->whereRaw("
    ST_Distance_Sphere(
        point(fobi_checklist_taxas.longitude, fobi_checklist_taxas.latitude),
        point(?, ?)
    ) <= ?
", [$lng, $lat, $radius * 1000]);
}

// Filter tanggal
if ($request->has('start_date')) {
$baseQuery->where('fobi_checklist_taxas.created_at', '>=', $request->start_date);
}
if ($request->has('end_date')) {
$baseQuery->where('fobi_checklist_taxas.created_at', '<=', $request->end_date);
}

// Tambahkan filter polygon jika ada
if ($request->has('polygon')) {
    $polygonCoords = explode('|', $request->polygon);
    $polygonPoints = [];
    
    foreach ($polygonCoords as $coord) {
        list($lng, $lat) = explode(',', $coord);
        $polygonPoints[] = [$lng, $lat];
    }
    
    // Dapatkan bounding box dari polygon untuk filter awal
    $minLat = $maxLat = $polygonPoints[0][1];
    $minLng = $maxLng = $polygonPoints[0][0];
    
    foreach ($polygonPoints as $point) {
        $minLat = min($minLat, $point[1]);
        $maxLat = max($maxLat, $point[1]);
        $minLng = min($minLng, $point[0]);
        $maxLng = max($maxLng, $point[0]);
    }
    
    // Filter berdasarkan bounding box terlebih dahulu (lebih efisien)
    $baseQuery->where('fobi_checklist_taxas.latitude', '>=', $minLat)
              ->where('fobi_checklist_taxas.latitude', '<=', $maxLat)
              ->where('fobi_checklist_taxas.longitude', '>=', $minLng)
              ->where('fobi_checklist_taxas.longitude', '<=', $maxLng);
    
    // Simpan polygon points untuk digunakan di PHP
    $request->merge(['polygon_points' => $polygonPoints]);
}

// Step 2: Paginate IDs
$observationIds = $baseQuery
->pluck('id'); // Hapus orderBy di sini karena sudah diurutkan di atas

// Step 3: Ambil detail observations dengan eager loading dan tetap urutannya
$observations = DB::table('fobi_checklist_taxas')
->whereIn('fobi_checklist_taxas.id', $observationIds)
->orderByRaw("FIELD(fobi_checklist_taxas.id, " . implode(',', $observationIds->toArray()) . ")") // Tambahkan ini untuk mempertahankan urutan
->join('taxa_quality_assessments', 'fobi_checklist_taxas.id', '=', 'taxa_quality_assessments.taxa_id')
->join('fobi_users', 'fobi_checklist_taxas.user_id', '=', 'fobi_users.id')
->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
->select([
    'fobi_checklist_taxas.id',
    'fobi_checklist_taxas.taxa_id',
    'fobi_checklist_taxas.scientific_name',
    'fobi_checklist_taxas.genus',
    'fobi_checklist_taxas.species',
    'fobi_checklist_taxas.family',
    'taxas.cname_species',
    'taxas.cname_genus',
    'taxas.cname_family',
    'taxas.cname_order',
    'taxas.cname_class',
    'taxas.cname_phylum',
    'taxas.cname_kingdom',
    'taxas.cname_superkingdom',
    'taxas.cname_superphylum',
    'taxas.cname_superclass',
    'taxas.cname_superorder',
    'taxas.cname_superfamily',
    'taxas.cname_infraclass',
    'taxas.cname_domain',
    'taxas.cname_division',
    'taxas.kingdom',
    'taxas.subkingdom',
    'taxas.superkingdom',
    'taxas.phylum',
    'taxas.subphylum',
    'taxas.superphylum',
    'taxas.division',
    'taxas.superdivision',
    'taxas.class',
    'taxas.subclass',
    'taxas.infraclass',
    'taxas.order',
    'taxas.suborder',
    'taxas.superorder',
    'taxas.infraorder',
    'taxas.superfamily',
    'taxas.family',
    'taxas.subfamily',
    'taxas.tribe',
    'taxas.subtribe',
    'taxas.genus',
    'taxas.species',
    'taxas.form',
    'taxas.variety',
    'fobi_checklist_taxas.latitude',
    'fobi_checklist_taxas.longitude',
    'fobi_checklist_taxas.date as observation_date',
    'fobi_checklist_taxas.created_at',
    'taxa_quality_assessments.grade',
    'taxa_quality_assessments.has_media',
    'taxa_quality_assessments.needs_id',
    'fobi_users.uname as observer_name',
    'fobi_users.id as observer_id'
])
->paginate($perPage);

// Step 4: Ambil semua media dalam satu query
$mediaData = DB::table('fobi_checklist_media')
->whereIn('checklist_id', $observations->pluck('id'))
->get()
->groupBy('checklist_id');

// Step 5: Ambil fobi counts dalam satu query
$fobiCounts = DB::table('fobi_checklist_taxas')
->whereIn('taxa_id', $observations->pluck('taxa_id'))
->select('taxa_id', DB::raw('count(*) as count'))
->groupBy('taxa_id')
->pluck('count', 'taxa_id');

// Step 6: Format data
foreach ($observations as $observation) {
$medias = $mediaData[$observation->id] ?? collect();

$observation->images = [];
$observation->audioUrl = null;
$observation->spectrogram = null;

foreach ($medias as $media) {
    if ($media->media_type === 'photo') {
        $observation->images[] = [
            'id' => $media->id,
            'media_type' => 'photo',
            'url' => asset('storage/' . $media->file_path)
        ];
    } else if ($media->media_type === 'audio') {
        $observation->audioUrl = asset('storage/' . $media->file_path);
        $observation->spectrogram = asset('storage/' . $media->spectrogram);
    }
}

$observation->image = !empty($observation->images)
    ? $observation->images[0]['url']
    : asset('images/default-thumbnail.jpg');

$observation->fobi_count = $fobiCounts[$observation->taxa_id] ?? 0;
$observation->source = 'fobi';

// Di method getObservations
$observation->total_identifications = DB::table('taxa_identifications')
    ->where('checklist_id', $observation->id)
    ->whereNull('burnes_checklist_id')
    ->whereNull('kupnes_checklist_id')
    ->where(function($query) {
        $query->where('is_agreed', true)
              ->orWhereNull('is_agreed');
    })
    ->where(function($query) {
        $query->where('is_withdrawn', false)
              ->orWhereNull('is_withdrawn');
    })
    ->count();

// Juga tambahkan perhitungan agreement count
$observation->agreement_count = DB::table('taxa_identifications')
    ->where('checklist_id', $observation->id)
    ->where('is_agreed', true)
    ->whereNull('burnes_checklist_id')
    ->whereNull('kupnes_checklist_id')
    ->where('is_withdrawn', false)
    ->count();
}

return response()->json([
'success' => true,
'data' => $observations->items(),
'meta' => [
    'current_page' => $observations->currentPage(),
    'per_page' => $perPage,
    'total' => $observations->total(),
    'last_page' => $observations->lastPage()
],
'links' => [
    'first' => $observations->url(1),
    'last' => $observations->url($observations->lastPage()),
    'prev' => $observations->previousPageUrl(),
    'next' => $observations->nextPageUrl()
]
]);

} catch (\Exception $e) {
// Perbaiki format logging error
Log::error('Error in getObservations:', ['message' => $e->getMessage()]);
Log::error('Stack trace:', ['trace' => $e->getTraceAsString()]);

return response()->json([
'success' => false,
'message' => 'Terjadi kesalahan saat mengambil data observasi: ' . $e->getMessage()
], 500);
}
}

public function getUserObservations(Request $request)
    {
        try {
            $userId = JWTAuth::parseToken()->authenticate()->id;
            $perPage = $request->input('per_page', 50);

            $observations = DB::table('fobi_checklist_taxas')
                ->join('taxa_quality_assessments', 'fobi_checklist_taxas.id', '=', 'taxa_quality_assessments.taxa_id')
                ->join('fobi_users', 'fobi_checklist_taxas.user_id', '=', 'fobi_users.id')
                ->join('taxas', 'fobi_checklist_taxas.taxa_id', '=', 'taxas.id')
                ->where('fobi_checklist_taxas.user_id', $userId)
                ->select(
                    'fobi_checklist_taxas.*',
                    'taxa_quality_assessments.grade',
                    'taxa_quality_assessments.has_media',
                    'taxa_quality_assessments.is_wild',
                    'taxa_quality_assessments.location_accurate',
                    'taxa_quality_assessments.recent_evidence',
                    'taxa_quality_assessments.related_evidence',
                    'taxa_quality_assessments.needs_id',
                    'taxa_quality_assessments.community_id_level',
                    'fobi_users.uname as observer_name',
                    DB::raw('(SELECT COUNT(DISTINCT user_id) FROM taxa_identifications WHERE checklist_id = fobi_checklist_taxas.id) as identifications_count')
                )
                ->orderBy('fobi_checklist_taxas.created_at', 'desc')
                ->paginate($perPage);

            // Proses setiap observasi untuk menambahkan media
            foreach ($observations as $observation) {
                // Ambil media
                $medias = DB::table('fobi_checklist_media')
                    ->where('checklist_id', $observation->id)
                    ->get();

                $observation->images = [];
                $observation->audioUrl = null;
                $observation->spectrogram = null;

                foreach ($medias as $media) {
                    if ($media->media_type === 'photo') {
                        $observation->images[] = [
                            'id' => $media->id,
                            'url' => asset('storage/' . $media->file_path)
                        ];
                    } else if ($media->media_type === 'audio') {
                        $observation->audioUrl = asset('storage/' . $media->file_path);
                        $observation->spectrogram = asset('storage/' . $media->spectrogram);
                    }
                }
                $observation->fobi_count = DB::table('fobi_checklist_taxas')
                ->where('taxa_id', $observation->taxa_id)
                ->count();

            }

            return response()->json([
                'success' => true,
                'data' => $observations->items(),
                'meta' => [
                    'current_page' => $observations->currentPage(),
                    'per_page' => $observations->perPage(),
                    'total' => $observations->total(),
                    'last_page' => $observations->lastPage()
                ],
                'links' => [
                    'first' => $observations->url(1),
                    'last' => $observations->url($observations->lastPage()),
                    'prev' => $observations->previousPageUrl(),
                    'next' => $observations->nextPageUrl()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching user observations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data observasi pengguna'
            ], 500);
        }
    }

    /**
     * Handle cropped image upload
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function cropImage(Request $request)
    {
        try {
            // Validasi request
            $request->validate([
                'image' => 'required|image|max:10240', // Max 10MB
                'max_dimension' => 'nullable|integer|min:100|max:2000',
                'quality' => 'nullable|integer|min:10|max:100'
            ]);

            // Ambil file dari request
            $image = $request->file('image');
            
            // Ambil parameter opsional
            $maxDimension = $request->input('max_dimension', 1000); // Default 1000px
            $quality = $request->input('quality', 80); // Default 80%

            // Buat instance Intervention Image
            $img = Image::make($image);

            // Resize gambar jika dimensinya melebihi max_dimension
            $width = $img->width();
            $height = $img->height();
            
            if ($width > $maxDimension || $height > $maxDimension) {
                if ($width > $height) {
                    $img->resize($maxDimension, null, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                } else {
                    $img->resize(null, $maxDimension, function ($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    });
                }
            }

            // Konversi ke base64
            $base64Image = 'data:image/jpeg;base64,' . base64_encode($img->encode('jpg', $quality)->encoded);

            return response()->json([
                'success' => true,
                'data' => $base64Image
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses gambar: ' . $e->getMessage()
            ], 500);
        }
    }
}


