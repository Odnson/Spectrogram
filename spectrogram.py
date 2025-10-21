import sys
import numpy as np
import matplotlib.pyplot as plt
from pydub import AudioSegment
from scipy.signal import spectrogram
import os

def create_spectrogram_frames(audio_file, output_dir, frame_duration=0.1):
    """
    Membuat sequence frame spectrogram
    frame_duration: durasi per frame dalam detik
    """
    if not os.path.exists(output_dir):
        os.makedirs(output_dir)

    audio = AudioSegment.from_file(audio_file)
    samples = np.array(audio.get_array_of_samples())
    sample_rate = audio.frame_rate

    samples_per_frame = int(frame_duration * sample_rate)
    total_frames = len(samples) // samples_per_frame

    nperseg = 1024
    noverlap = nperseg // 4

    for i in range(total_frames):
        start_idx = i * samples_per_frame
        end_idx = start_idx + samples_per_frame

        frame_samples = samples[start_idx:end_idx]

        f, t, Sxx = spectrogram(frame_samples,
                               fs=sample_rate,
                               nperseg=nperseg,
                               noverlap=noverlap,
                               window='hann')

        Sxx = Sxx.astype(np.float64)

        threshold = np.percentile(Sxx, 75)
        Sxx[Sxx < threshold] = 0

        plt.figure(figsize=(4, 4))
        plt.pcolormesh(t, f, 10 * np.log10(Sxx),
                      shading='gouraud',
                      cmap='gray_r')
        plt.ylim(0, 5000)
        plt.axis('off')

        frame_path = os.path.join(output_dir, f'frame_{i:04d}.png')
        plt.savefig(frame_path,
                   format='png',
                   bbox_inches='tight',
                   pad_inches=0,
                   dpi=100)
        plt.close()

    return total_frames

def create_spectrogram(audio_file, output_image):
    """
    Membuat spectrogram statis (versi original)
    """
    audio = AudioSegment.from_file(audio_file)
    samples = np.array(audio.get_array_of_samples())
    sample_rate = audio.frame_rate

    f, t, Sxx = spectrogram(samples, fs=sample_rate, nperseg=1024, noverlap=512, window='hann')

    Sxx = Sxx.astype(np.float64)

    threshold = np.percentile(Sxx, 75)
    Sxx[Sxx < threshold] = 0

    plt.figure(figsize=(len(samples) / sample_rate, 6))
    plt.pcolormesh(t, f, 10 * np.log10(Sxx), shading='gouraud', cmap='gray_r')
    plt.ylim(0, 5000)
    plt.axis('off')
    plt.savefig(output_image, format='png', bbox_inches='tight', pad_inches=0)
    plt.close()

if __name__ == '__main__':
    audio_file = sys.argv[1]
    output_path = sys.argv[2]

    if output_path.endswith('/'):
        total_frames = create_spectrogram_frames(audio_file, output_path)
        print(f"Created {total_frames} frames")
    else:

        create_spectrogram(audio_file, output_path)
