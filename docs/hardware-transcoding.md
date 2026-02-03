# Hardware Transcoding for FFmpeg and Jellyfin

This guide covers hardware-accelerated video transcoding on PMSS servers. Hardware transcoding uses the GPU/iGPU to encode and decode video, significantly reducing CPU usage and improving transcode performance.

## Hardware Detection

Before configuring hardware transcoding, identify what hardware is available.

### List GPUs and Video Devices

```bash
# List all VGA and 3D controllers
lspci | grep -E 'VGA|3D|Display'

# More detailed output
lspci -nn | grep -E 'VGA|3D|Display'
```

Example outputs:

```
# Intel iGPU (common on seedbox servers)
00:02.0 VGA compatible controller [0300]: Intel Corporation CoffeeLake-H GT2 [UHD Graphics 630] [8086:3e9b]

# AMD iGPU
06:00.0 VGA compatible controller [0300]: Advanced Micro Devices, Inc. [AMD/ATI] Raven Ridge [Radeon Vega Series / Radeon Vega Mobile Series] [1002:15dd]
```

### Check VAAPI Support

VAAPI (Video Acceleration API) is the primary hardware acceleration method on Linux.

```bash
# Check if VAAPI device exists
ls -la /dev/dri/

# Should show:
# renderD128 - the render node for hardware acceleration
# card0      - the DRM card device

# Detailed VAAPI info (requires vainfo package)
vainfo
```

If `vainfo` is not installed:
```bash
# Debian/Ubuntu (requires root)
apt install vainfo
```

Example `vainfo` output for Intel:
```
libva info: VA-API version 1.14.0
libva info: Trying to open /usr/lib/x86_64-linux-gnu/dri/iHD_drv_video.so
libva info: Found init function __vaDriverInit_1_14
libva info: va_openDriver() returns 0
vainfo: VA-API version: 1.14 (libva 2.12.0)
vainfo: Driver version: Intel iHD driver for Intel(R) Gen Graphics - 22.3.1 ()
vainfo: Supported profile and entrypoints
      VAProfileH264Main               : VAEntrypointVLD
      VAProfileH264Main               : VAEntrypointEncSlice
      VAProfileH264High               : VAEntrypointVLD
      VAProfileH264High               : VAEntrypointEncSlice
      VAProfileHEVCMain               : VAEntrypointVLD
      VAProfileHEVCMain               : VAEntrypointEncSlice
      VAProfileHEVCMain10             : VAEntrypointVLD
      VAProfileHEVCMain10             : VAEntrypointEncSlice
```

Key profiles to look for:
- `VAEntrypointVLD` - hardware decode
- `VAEntrypointEncSlice` - hardware encode

## Intel iGPU Setup

Intel iGPUs are the most common and best-supported for hardware transcoding on Linux.

### Driver Installation (System-Level)

The system administrator needs to install VAAPI drivers. On Debian/Ubuntu:

```bash
# Intel Media Driver (newer Intel, Gen 8+, recommended)
apt install intel-media-va-driver

# Or legacy i965 driver (older Intel, pre-Gen 8)
apt install i965-va-driver

# Both can coexist; the system picks the appropriate one
```

### Render Group Membership

Users must be in the `render` group to access `/dev/dri/renderD128`:

```bash
# Check current groups
groups

# Check if render group exists
getent group render

# Add user to render group (requires root)
usermod -aG render <username>

# User must log out and back in for group change to take effect
```

To verify access:
```bash
# Should succeed if in render group
ls -la /dev/dri/renderD128

# Test with vainfo
vainfo
```

If you get permission errors, the user is not in the render group.

### Environment Variables

Set the VAAPI driver explicitly if needed:

```bash
# For Intel Media Driver (iHD)
export LIBVA_DRIVER_NAME=iHD

# For legacy i965 driver
export LIBVA_DRIVER_NAME=i965
```

Add to `~/.bashrc.custom` for persistence.

## AMD iGPU Notes

AMD iGPU support (Vega, RDNA) on Linux is less mature than Intel.

### What Works

- **Video decode**: Generally works with Mesa VAAPI drivers
- **AV1 decode**: Works on RDNA2+ with recent Mesa

### What Does Not Work (or is limited)

- **Hardware encode**: AMD GPUs on Linux have limited or no VAAPI encode support
  - Vega iGPUs: No hardware encode via VAAPI
  - RDNA1/2: Limited AMF support, not well integrated with FFmpeg VAAPI
- **10-bit HEVC encode**: Not supported on most AMD Linux configurations

### AMD Driver Setup

```bash
# Mesa VAAPI driver (open source, recommended)
apt install mesa-va-drivers

# Check AMD VAAPI support
LIBVA_DRIVER_NAME=radeonsi vainfo
```

### Practical Advice for AMD

If you have an AMD iGPU and need transcoding:
1. Use hardware decode only (reduces CPU load for playback)
2. Fall back to software encode (x264/x265 CPU encoding)
3. Consider Intel QuickSync if available as primary transcode target

## FFmpeg Configuration

FFmpeg must be built with VAAPI support and invoked with the correct flags.

### Check FFmpeg VAAPI Support

```bash
# Check if FFmpeg has VAAPI support
ffmpeg -hwaccels 2>/dev/null | grep vaapi

# List VAAPI encoders
ffmpeg -encoders 2>/dev/null | grep vaapi

# List VAAPI decoders
ffmpeg -decoders 2>/dev/null | grep vaapi
```

Expected output for full support:
```
 V..... h264_vaapi           H.264/AVC (VAAPI) (codec h264)
 V..... hevc_vaapi           H.265/HEVC (VAAPI) (codec hevc)
 V..... mjpeg_vaapi          MJPEG (VAAPI) (codec mjpeg)
 V..... mpeg2_vaapi          MPEG-2 (VAAPI) (codec mpeg2video)
 V..... vp8_vaapi            VP8 (VAAPI) (codec vp8)
 V..... vp9_vaapi            VP9 (VAAPI) (codec vp9)
```

### Hardware-Accelerated Transcoding Examples

Basic VAAPI transcode (H.264 to H.264):
```bash
ffmpeg -vaapi_device /dev/dri/renderD128 \
  -i input.mkv \
  -vf 'format=nv12,hwupload' \
  -c:v h264_vaapi \
  -c:a copy \
  output.mp4
```

Full hardware decode + encode pipeline:
```bash
ffmpeg -hwaccel vaapi \
  -hwaccel_device /dev/dri/renderD128 \
  -hwaccel_output_format vaapi \
  -i input.mkv \
  -c:v h264_vaapi \
  -c:a aac \
  output.mp4
```

HEVC/H.265 transcode:
```bash
ffmpeg -hwaccel vaapi \
  -hwaccel_device /dev/dri/renderD128 \
  -hwaccel_output_format vaapi \
  -i input.mkv \
  -c:v hevc_vaapi \
  -c:a copy \
  output.mp4
```

With quality settings (lower is better, range varies by encoder):
```bash
ffmpeg -hwaccel vaapi \
  -hwaccel_device /dev/dri/renderD128 \
  -hwaccel_output_format vaapi \
  -i input.mkv \
  -c:v h264_vaapi -qp 23 \
  -c:a copy \
  output.mp4
```

### Scaling with Hardware

Scale on GPU (keeps frames in GPU memory):
```bash
ffmpeg -hwaccel vaapi \
  -hwaccel_device /dev/dri/renderD128 \
  -hwaccel_output_format vaapi \
  -i input.mkv \
  -vf 'scale_vaapi=w=1280:h=720' \
  -c:v h264_vaapi \
  -c:a copy \
  output.mp4
```

### Tone Mapping (HDR to SDR)

For HDR content with tone mapping (requires OpenCL or Vulkan):
```bash
ffmpeg -hwaccel vaapi \
  -hwaccel_device /dev/dri/renderD128 \
  -hwaccel_output_format vaapi \
  -i hdr_input.mkv \
  -vf 'hwdownload,format=p010le,tonemap=hable:desat=0,format=nv12,hwupload' \
  -c:v h264_vaapi \
  -c:a copy \
  sdr_output.mp4
```

Note: Tone mapping is CPU-intensive; this is a known limitation.

## Jellyfin Integration

Jellyfin can use hardware transcoding when properly configured.

### Dashboard Configuration

1. Navigate to Dashboard (Admin) > Playback > Transcoding
2. Set Hardware acceleration to `Video Acceleration API (VAAPI)`
3. Set VA-API Device to `/dev/dri/renderD128`
4. Enable the codecs your hardware supports:
   - H264, HEVC, VP9, etc. based on `vainfo` output

### FFmpeg Path Configuration

If using a custom FFmpeg build (e.g., in `~/.bin/ffmpeg`):

1. Dashboard > Playback > Transcoding
2. Set FFmpeg path to `/home/<user>/.bin/ffmpeg`

Or use the installer flag:
```bash
bash install-media-stack.sh --jellyfin-ffmpeg=/home/<user>/.bin/ffmpeg
```

### Jellyfin VAAPI Settings

Recommended settings for Intel VAAPI:

| Setting | Value |
|---------|-------|
| Hardware acceleration | VAAPI |
| VA-API Device | /dev/dri/renderD128 |
| Enable hardware decoding | H264, HEVC, VP9 (as supported) |
| Enable hardware encoding | H264, HEVC (as supported) |
| Enable low-power encoding | Try enabled, disable if issues |
| Enable VPP tone mapping | Enable for HDR content |

### Library Setup for User-Space Drivers

If driver libraries are in user space (`~/.local/lib`), set environment before starting Jellyfin:

```bash
export LD_LIBRARY_PATH=$HOME/.local/lib:$HOME/.bin/lib:$LD_LIBRARY_PATH
```

Add this to `~/.bashrc.custom` for the media stack launcher to inherit.

## Verification

Confirm hardware transcoding is working.

### During Transcode

Monitor GPU usage while transcoding:

```bash
# Intel GPU usage (requires intel-gpu-tools, root)
intel_gpu_top

# Generic DRM info
cat /sys/kernel/debug/dri/0/i915_frequency_info

# Watch render device activity
ls -la /dev/dri/renderD128  # access time updates during use
```

### FFmpeg Test

Run a quick transcode and check for VAAPI usage:
```bash
ffmpeg -hwaccel vaapi \
  -hwaccel_device /dev/dri/renderD128 \
  -hwaccel_output_format vaapi \
  -i input.mkv \
  -c:v h264_vaapi \
  -t 10 \
  -f null - 2>&1 | grep -i vaapi
```

Look for:
- No errors about VAAPI device
- `Using hardware acceleration` messages
- Encoder line showing `h264_vaapi` or similar

### Jellyfin Verification

1. Play a video that requires transcoding (unsupported format or lower bandwidth client)
2. Check Dashboard > Playback > Active Transcoding
3. Look for `(HW)` next to the codec, indicating hardware acceleration
4. Check Jellyfin logs for:
   ```
   [hardware] Opening hardware device "vaapi" for encoder "h264_vaapi"
   ```

### CPU Usage Check

Compare CPU usage with and without hardware transcoding:
- Software encode: High CPU usage (50-100% of multiple cores)
- Hardware encode: Low CPU usage (5-20%), GPU handles the work

## Troubleshooting

### Common Issues

#### Permission Denied on /dev/dri/renderD128

```
Failed to open VAAPI device: Permission denied
```

**Solution**: Add user to render group:
```bash
# As root
usermod -aG render <username>
# User must log out and back in
```

#### No VAAPI Support / Driver Not Found

```
libva error: /usr/lib/x86_64-linux-gnu/dri/iHD_drv_video.so init failed
```

**Solution**: Install the correct driver:
```bash
# Intel (newer)
apt install intel-media-va-driver

# Intel (older)
apt install i965-va-driver

# AMD
apt install mesa-va-drivers
```

#### Wrong Driver Selected

```
vaInitialize failed with error code -1 (unknown libva error)
```

**Solution**: Force the correct driver:
```bash
# Intel Media Driver
export LIBVA_DRIVER_NAME=iHD

# i965 legacy
export LIBVA_DRIVER_NAME=i965

# AMD
export LIBVA_DRIVER_NAME=radeonsi
```

#### Encoder Not Available

```
Unknown encoder 'h264_vaapi'
```

**Solution**: FFmpeg was not built with VAAPI support. Use a build that includes VAAPI:
- Check `ffmpeg -encoders | grep vaapi`
- Use static builds from https://johnvansickle.com/ffmpeg/ or https://github.com/BtbN/FFmpeg-Builds

#### Jellyfin Shows Software Transcode Despite Settings

1. Verify Jellyfin can access the render device:
   - Check Jellyfin logs for VAAPI errors
   - Ensure the user running Jellyfin is in the render group
2. Check FFmpeg path in Jellyfin settings matches a VAAPI-capable build
3. Verify the specific codec is enabled in Jellyfin transcoding settings

#### Poor Quality Output

Hardware encoders prioritize speed over quality. To improve:
- Lower the QP value (e.g., `-qp 20` instead of `-qp 28`)
- Use a slower preset if available
- For critical encodes, consider software encoding

#### Transcoding Works But Is Slow

1. Check if using hardware decode AND encode (not just one)
2. Verify GPU is not thermally throttling
3. Ensure no software filters forcing CPU processing

### Debugging Commands

```bash
# Full VAAPI debug output
LIBVA_MESSAGING_LEVEL=2 vainfo

# FFmpeg debug for hardware init
ffmpeg -v verbose -hwaccel vaapi -hwaccel_device /dev/dri/renderD128 -i input.mkv -f null - 2>&1 | head -50

# Check kernel messages for GPU issues
dmesg | grep -i -E 'drm|i915|amdgpu|vaapi'
```

## Summary

| Component | Intel iGPU | AMD iGPU |
|-----------|------------|----------|
| Decode | Excellent | Good |
| Encode | Excellent | Limited/None |
| Driver | intel-media-va-driver | mesa-va-drivers |
| Recommendation | Full HW transcode | Decode-only, CPU encode |

For PMSS shared hosting, Intel-based servers are preferred for hardware transcoding. AMD systems can use hardware decode to reduce CPU load during playback but will rely on CPU for encoding.
