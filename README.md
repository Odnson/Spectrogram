# Spectrogram Generator

A Python script to generate spectrograms from audio files. This tool creates visual representations of audio frequencies over time, useful for audio analysis and machine learning applications.

## Features

- Generate static spectrograms from audio files
- Create sequence of spectrogram frames for video processing
- Support for various audio formats (MP3, WAV, OGG, AAC, M4A, MP4)
- Automatic noise filtering and thresholding
- Configurable frame duration for sequence generation

## Requirements

- Python 3.7+
- Dependencies listed in `requirements.txt`:
  - numpy>=1.21.0
  - matplotlib>=3.5.0
  - pydub>=0.25.0
  - scipy>=1.7.0

## Installation

1. Clone or download the repository
2. Install dependencies:
   ```bash
   pip install -r requirements.txt
   ```

## Usage

### Command Line Interface

The script accepts two arguments:
- `audio_file`: Path to the input audio file
- `output_path`: Path for output spectrogram

#### Generate Static Spectrogram

```bash
python spectrogram.py /path/to/audio.mp3 /path/to/output.png
```

This creates a single spectrogram image from the entire audio file.

#### Generate Spectrogram Frames (Sequence)

```bash
python spectrogram.py /path/to/audio.mp3 /path/to/output/directory/
```

When the output path ends with `/`, the script generates a sequence of spectrogram frames. Each frame represents 0.1 seconds of audio and is saved as `frame_XXXX.png` where XXXX is the frame number.

## Integration Examples

The spectrogram script can be integrated into various web frameworks. Below are examples for different popular frameworks:

### Laravel/PHP

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AudioAnalysisController extends Controller
{
    public function generateSpectrogram(Request $request)
    {
        try {
            $request->validate([
                'audio_file' => 'required|file|mimes:mp3,wav,ogg,aac,m4a,mp4|max:15120',
            ]);

            // Store uploaded audio file
            $audioPath = $request->file('audio_file')->store('audio', 'public');
            $spectrogramPath = preg_replace('/\.(mp3|wav|ogg|aac|m4a|mp4)$/i', '.png', $audioPath);

            // Set environment variables for Python execution
            $env = [
                'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
                'PYTHONPATH' => '/path/to/your/python/environment/lib/python3.x/site-packages'
            ];

            // Build command to execute spectrogram script
            $pythonPath = '/path/to/your/python/environment/bin/python';
            $scriptPath = base_path('scripts/spectrogram.py');
            $audioFullPath = storage_path('app/public/' . $audioPath);
            $spectrogramFullPath = storage_path('app/public/' . $spectrogramPath);

            $command = escapeshellcmd("$pythonPath $scriptPath \"$audioFullPath\" \"$spectrogramFullPath\"");

            // Execute command
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
                $returnCode = proc_close($process);

                Log::info('Spectrogram generation result', [
                    'return_code' => $returnCode,
                    'stdout' => $stdout,
                    'stderr' => $stderr
                ]);

                if (Storage::disk('public')->exists($spectrogramPath)) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Spectrogram generated successfully',
                        'spectrogram_url' => asset('storage/' . $spectrogramPath),
                        'audio_url' => asset('storage/' . $audioPath)
                    ]);
                }
            }

            throw new \Exception('Failed to generate spectrogram');

        } catch (\Exception $e) {
            Log::error('Spectrogram generation error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating spectrogram',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
```

**API Endpoint:**
```php
// routes/api.php
use App\Http\Controllers\Api\AudioAnalysisController;

Route::post('/audio/generate-spectrogram', [AudioAnalysisController::class, 'generateSpectrogram']);
```

### Express.js/Node.js

```javascript
const express = require('express');
const multer = require('multer');
const { exec } = require('child_process');
const path = require('path');
const fs = require('fs');

const app = express();
const upload = multer({ dest: 'uploads/' });

app.post('/api/audio/generate-spectrogram', upload.single('audio_file'), (req, res) => {
    try {
        if (!req.file) {
            return res.status(400).json({
                success: false,
                message: 'Audio file is required'
            });
        }

        const audioPath = req.file.path;
        const spectrogramPath = audioPath.replace(/\.(mp3|wav|ogg|aac|m4a|mp4)$/i, '.png');

        // Execute Python script
        const pythonCommand = `python3 ${path.join(__dirname, 'scripts', 'spectrogram.py')} "${audioPath}" "${spectrogramPath}"`;

        exec(pythonCommand, { env: { ...process.env, PYTHONPATH: '/path/to/python/site-packages' } }, (error, stdout, stderr) => {
            // Clean up uploaded file
            fs.unlinkSync(audioPath);

            if (error) {
                console.error('Spectrogram generation error:', error);
                return res.status(500).json({
                    success: false,
                    message: 'Failed to generate spectrogram',
                    error: error.message
                });
            }

            if (fs.existsSync(spectrogramPath)) {
                const spectrogramUrl = `/spectrograms/${path.basename(spectrogramPath)}`;
                res.json({
                    success: true,
                    message: 'Spectrogram generated successfully',
                    spectrogram_url: spectrogramUrl,
                    audio_filename: req.file.originalname
                });
            } else {
                res.status(500).json({
                    success: false,
                    message: 'Spectrogram file was not created'
                });
            }
        });

    } catch (error) {
        console.error('Server error:', error);
        res.status(500).json({
            success: false,
            message: 'Internal server error',
            error: error.message
        });
    }
});

app.listen(3000, () => {
    console.log('Server running on port 3000');
});
```

### Ruby on Rails

```ruby
# app/controllers/audio_analysis_controller.rb
class AudioAnalysisController < ApplicationController
    def generate_spectrogram
        begin
            # Validate file upload
            uploaded_file = params[:audio_file]
            unless uploaded_file && valid_audio_file?(uploaded_file)
                render json: { success: false, message: 'Valid audio file is required' }, status: :bad_request
                return
            end

            # Save uploaded file
            audio_filename = SecureRandom.hex + File.extname(uploaded_file.original_filename)
            audio_path = Rails.root.join('tmp', 'uploads', audio_filename)
            File.open(audio_path, 'wb') do |file|
                file.write(uploaded_file.read)
            end

            # Generate spectrogram path
            spectrogram_filename = audio_filename.sub(/\.(mp3|wav|ogg|aac|m4a|mp4)$/i, '.png')
            spectrogram_path = Rails.root.join('public', 'spectrograms', spectrogram_filename)

            # Ensure spectrograms directory exists
            FileUtils.mkdir_p(Rails.root.join('public', 'spectrograms'))

            # Execute Python script
            python_path = '/path/to/python/bin/python3'
            script_path = Rails.root.join('scripts', 'spectrogram.py')
            env_vars = {
                'PYTHONPATH' => '/path/to/python/site-packages',
                'PATH' => ENV['PATH']
            }

            command = "#{python_path} #{script_path} \"#{audio_path}\" \"#{spectrogram_path}\""
            stdout, stderr, status = Open3.capture3(env_vars, command)

            # Clean up temporary file
            File.delete(audio_path) if File.exist?(audio_path)

            if status.success? && File.exist?(spectrogram_path)
                render json: {
                    success: true,
                    message: 'Spectrogram generated successfully',
                    spectrogram_url: "/spectrograms/#{spectrogram_filename}",
                    audio_filename: uploaded_file.original_filename
                }
            else
                Rails.logger.error("Spectrogram generation failed: #{stderr}")
                render json: {
                    success: false,
                    message: 'Failed to generate spectrogram',
                    error: stderr
                }, status: :internal_server_error
            end

        rescue => e
            Rails.logger.error("Spectrogram generation error: #{e.message}")
            render json: {
                success: false,
                message: 'Error generating spectrogram',
                error: e.message
            }, status: :internal_server_error
        end
    end

    private

    def valid_audio_file?(file)
        return false unless file
        allowed_extensions = %w[.mp3 .wav .ogg .aac .m4a .mp4]
        allowed_extensions.include?(File.extname(file.original_filename).downcase) &&
        file.size <= 15.megabytes
    end
end
```

**Routes:**
```ruby
# config/routes.rb
Rails.application.routes.draw do
    post '/api/audio/generate-spectrogram', to: 'audio_analysis#generate_spectrogram'
end
```

### Parameters

- **Frame Duration**: Default 0.1 seconds per frame (configurable in code)
- **Frequency Range**: 0-5000 Hz (configurable in code)
- **Noise Threshold**: 75th percentile filtering
- **Output Format**: PNG images
- **Colormap**: gray_r (inverted grayscale)

### Output

- **Static Spectrogram**: Single PNG image showing frequency vs time
- **Frame Sequence**: Multiple PNG files (frame_0000.png, frame_0001.png, etc.)

### Error Handling

The script includes basic error handling for:
- Invalid file paths
- Unsupported audio formats
- Processing failures

### Dependencies

- **numpy**: Numerical computations
- **matplotlib**: Plotting and image generation
- **pydub**: Audio file handling
- **scipy**: Signal processing for spectrogram generation

### Notes

- Audio files are automatically converted to compatible formats if needed
- Spectrograms are generated with logarithmic frequency scaling
- Noise filtering helps improve visualization quality
- Output images are optimized for machine learning applications

## License

This project is open source. Feel free to use and modify as needed.
