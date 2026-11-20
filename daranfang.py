#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
《大染坊》aTV / Apple TV TestFlight 版轮播源

这个版本只使用 Python 标准库，不依赖 VLC、python-vlc、ffmpeg 或第三方模块。
脚本启动后会在本机提供一个 HLS 地址：

    http://127.0.0.1:56789/daranfang.m3u8

另外提供一个普通 M3U 播放列表：

    http://127.0.0.1:56789/daranfang.m3u

用法：
    python3 daranfang_aptv.py
    python3 daranfang_aptv.py --reset

--reset 会从当前时间重新建立轮播时间轴。

说明：
  - /daranfang.m3u8 是将各集 HLS 媒体分片拼接后的本地播放源。
  - 脚本不下载视频，只读取远端 m3u8 清单，播放器仍直接读取远端分片。
  - 请确保播放地址是你有权访问和播放的合法地址。
"""

from __future__ import print_function

import argparse
import http.server
import json
import re
import socketserver
import ssl
import sys
import threading
import time

try:
    from concurrent.futures import ThreadPoolExecutor, as_completed
except ImportError:  # pragma: no cover - 现代 Python 都会有此模块
    ThreadPoolExecutor = None
    as_completed = None

try:
    from pathlib import Path
except ImportError:  # pragma: no cover - aTV 应使用 Python 3
    Path = None

try:
    from urllib.error import HTTPError, URLError
    from urllib.parse import urljoin, urlparse
    from urllib.request import Request, urlopen
except ImportError:  # pragma: no cover
    from urllib2 import HTTPError, URLError, Request, urlopen
    from urlparse import urljoin, urlparse


HOST = "127.0.0.1"
PORT = 56789
USER_AGENT = "Mozilla/5.0 (AppleTV; aTV Python HLS)"
FETCH_TIMEOUT = 20
CATALOG_REFRESH_SECONDS = 300
MANIFEST_WINDOW_SECONDS = 12 * 60 * 60

SCRIPT_DIR = Path(__file__).resolve().parent if Path is not None else None
STATE_FILE = (
    SCRIPT_DIR / "daranfang_aptv_state.json"
    if SCRIPT_DIR is not None
    else None
)


EPISODES = [
    {"title": "第1集", "duration": 2825, "url": "https://vip.dytt-play.com/20250611/29675_0a188292/3000k/hls/mixed.m3u8"},
    {"title": "第2集", "duration": 2803, "url": "https://vip.dytt-play.com/20250611/29676_dba595ed/3000k/hls/mixed.m3u8"},
    {"title": "第3集", "duration": 2805, "url": "https://vip.dytt-play.com/20250611/29677_5e27fa9f/3000k/hls/mixed.m3u8"},
    {"title": "第4集", "duration": 2805, "url": "https://vip.dytt-play.com/20250611/29678_40ddaee2/3000k/hls/mixed.m3u8"},
    {"title": "第5集", "duration": 2823, "url": "https://vip.dytt-play.com/20250611/29679_f7fd20e8/3000k/hls/mixed.m3u8"},
    {"title": "第6集", "duration": 2816, "url": "https://vip.dytt-play.com/20250611/29680_0caa694f/3000k/hls/mixed.m3u8"},
    {"title": "第7集", "duration": 2812, "url": "https://vip.dytt-play.com/20250611/29681_3eec1ed8/3000k/hls/mixed.m3u8"},
    {"title": "第8集", "duration": 2827, "url": "https://vip.dytt-play.com/20250611/29682_1ff655ed/3000k/hls/mixed.m3u8"},
    {"title": "第9集", "duration": 2819, "url": "https://vip.dytt-play.com/20250611/29683_ca1e7c16/3000k/hls/mixed.m3u8"},
    {"title": "第10集", "duration": 2807, "url": "https://vip.dytt-play.com/20250611/29684_eb077228/3000k/hls/mixed.m3u8"},
    {"title": "第11集", "duration": 2798, "url": "https://vip.dytt-play.com/20250611/29685_a5468f4a/3000k/hls/mixed.m3u8"},
    {"title": "第12集", "duration": 2812, "url": "https://vip.dytt-play.com/20250611/29686_a7329df9/3000k/hls/mixed.m3u8"},
    {"title": "第13集", "duration": 2817, "url": "https://vip.dytt-play.com/20250611/29687_79538b5b/3000k/hls/mixed.m3u8"},
    {"title": "第14集", "duration": 2807, "url": "https://vip.dytt-play.com/20250611/29688_967d1576/3000k/hls/mixed.m3u8"},
    {"title": "第15集", "duration": 2765, "url": "https://vip.dytt-play.com/20250611/29689_3a7666b0/3000k/hls/mixed.m3u8"},
    {"title": "第16集", "duration": 2813, "url": "https://vip.dytt-play.com/20250611/29690_7a2ce833/3000k/hls/mixed.m3u8"},
    {"title": "第17集", "duration": 2822, "url": "https://vip.dytt-play.com/20250611/29691_97fad653/3000k/hls/mixed.m3u8"},
    {"title": "第18集", "duration": 2802, "url": "https://vip.dytt-play.com/20250611/29692_f7e1e746/3000k/hls/mixed.m3u8"},
    {"title": "第19集", "duration": 2815, "url": "https://vip.dytt-play.com/20250611/29693_d7facbd2/3000k/hls/mixed.m3u8"},
    {"title": "第20集", "duration": 2815, "url": "https://vip.dytt-play.com/20250611/29694_97d84fba/3000k/hls/mixed.m3u8"},
    {"title": "第21集", "duration": 2823, "url": "https://vip.dytt-play.com/20250611/29695_766c626b/3000k/hls/mixed.m3u8"},
    {"title": "第22集", "duration": 2826, "url": "https://vip.dytt-play.com/20250611/29696_e50bea89/3000k/hls/mixed.m3u8"},
    {"title": "第23集", "duration": 2826, "url": "https://vip.dytt-play.com/20250611/29697_9d949090/3000k/hls/mixed.m3u8"},
    {"title": "第24集", "duration": 2800, "url": "https://vip.dytt-play.com/20250611/29698_371783b1/3000k/hls/mixed.m3u8"},
]


def parse_args():
    parser = argparse.ArgumentParser(description="aTV 本地 HLS 轮播源")
    parser.add_argument(
        "--reset",
        action="store_true",
        help="删除保存的时间轴，从当前时间重新开始",
    )
    parser.add_argument(
        "--host",
        default=HOST,
        help="监听地址，默认只监听本机 127.0.0.1",
    )
    parser.add_argument(
        "--port",
        type=int,
        default=PORT,
        help="监听端口，默认 56789",
    )
    return parser.parse_args()


def _state_candidates():
    candidates = []
    if STATE_FILE is not None:
        candidates.append(STATE_FILE)
    candidates.append(Path("/tmp/daranfang_aptv_state.json"))
    candidates.append(Path("daranfang_aptv_state.json"))

    result = []
    for item in candidates:
        item = Path(item)
        if item not in result:
            result.append(item)
    return result


def load_anchor(reset=False):
    candidates = _state_candidates()
    if reset:
        for path in candidates:
            try:
                if path.exists():
                    path.unlink()
            except OSError:
                pass

    for path in candidates:
        try:
            if not path.exists():
                continue
            state = json.loads(path.read_text(encoding="utf-8"))
            anchor = float(state.get("anchor_epoch"))
            if anchor > 0:
                return anchor
        except (OSError, ValueError, TypeError, AttributeError, KeyError):
            continue

    anchor = time.time()
    payload = json.dumps(
        {"anchor_epoch": anchor}, ensure_ascii=False, indent=2
    )
    for path in candidates:
        try:
            parent = path.parent
            if str(parent) not in ("", ".") and not parent.exists():
                parent.mkdir(parents=True)
            path.write_text(payload, encoding="utf-8")
            break
        except OSError:
            continue
    return anchor


def fetch_text(url):
    request = Request(
        url,
        headers={
            "User-Agent": USER_AGENT,
            "Accept": "application/vnd.apple.mpegurl, application/x-mpegURL, */*",
            "Connection": "close",
        },
    )
    try:
        response = urlopen(request, timeout=FETCH_TIMEOUT)
    except URLError as exc:
        # 某些 Python 沙盒没有系统 CA 证书。只有证书校验失败时才尝试兼容模式。
        message = str(exc)
        if "CERTIFICATE_VERIFY_FAILED" not in message:
            raise
        insecure_context = ssl._create_unverified_context()
        response = urlopen(
            request, timeout=FETCH_TIMEOUT, context=insecure_context
        )

    with response:
        raw = response.read()
        final_url = response.geturl()
    text = raw.decode("utf-8-sig", errors="replace")
    return text, final_url


def parse_attributes(value):
    attributes = {}
    pattern = re.compile(r'([A-Z0-9-]+)=("[^"]*"|[^,]*)')
    for match in pattern.finditer(value):
        item = match.group(2).strip()
        if len(item) >= 2 and item[0] == '"' and item[-1] == '"':
            item = item[1:-1]
        attributes[match.group(1)] = item
    return attributes


def rewrite_uri_attribute(tag, base_url):
    def replace(match):
        value = match.group(1)
        return 'URI="{}"'.format(urljoin(base_url, value))

    return re.sub(r'URI="([^"]*)"', replace, tag)


def choose_variant(text, base_url):
    lines = [line.strip() for line in text.splitlines() if line.strip()]
    variants = []
    for index, line in enumerate(lines):
        if not line.startswith("#EXT-X-STREAM-INF:"):
            continue
        next_line = None
        for candidate in lines[index + 1 :]:
            if not candidate.startswith("#"):
                next_line = candidate
                break
        if next_line is None:
            continue
        attributes = parse_attributes(line.split(":", 1)[1])
        try:
            bandwidth = int(attributes.get("BANDWIDTH", "0"))
        except ValueError:
            bandwidth = 0
        variants.append((bandwidth, urljoin(base_url, next_line)))

    if not variants:
        return None
    variants.sort(key=lambda item: item[0], reverse=True)
    return variants[0][1]


def parse_media_playlist(text, base_url, depth=0):
    if depth > 3:
        raise RuntimeError("HLS 清单嵌套层级过深：{}".format(base_url))

    variant_url = choose_variant(text, base_url)
    if variant_url is not None:
        variant_text, variant_final_url = fetch_text(variant_url)
        return parse_media_playlist(variant_text, variant_final_url, depth + 1)

    segments = []
    active_key = None
    active_map = None
    pending_tags = []
    pending_duration = None

    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line:
            continue

        if line.startswith("#EXT-X-KEY:"):
            active_key = rewrite_uri_attribute(line, base_url)
            continue

        if line.startswith("#EXT-X-MAP:"):
            active_map = rewrite_uri_attribute(line, base_url)
            continue

        if line.startswith("#EXT-X-BYTERANGE:"):
            pending_tags.append(line)
            continue

        if line == "#EXT-X-DISCONTINUITY":
            pending_tags.append(line)
            continue

        if line.startswith("#EXTINF:"):
            value = line.split(":", 1)[1].split(",", 1)[0]
            try:
                pending_duration = float(value)
            except ValueError:
                pending_duration = None
            continue

        if line.startswith("#"):
            continue

        if pending_duration is None:
            continue

        tags = []
        if active_key is not None:
            tags.append(active_key)
        if active_map is not None:
            tags.append(active_map)
        tags.extend(pending_tags)
        segments.append(
            {
                "duration": pending_duration,
                "url": urljoin(base_url, line),
                "tags": tags,
            }
        )
        pending_tags = []
        pending_duration = None

    if not segments:
        raise RuntimeError("没有找到媒体分片：{}".format(base_url))

    target_duration = max(
        int(segment["duration"] + 0.999) for segment in segments
    )
    return {"segments": segments, "target_duration": target_duration}


def load_episode(index):
    episode = EPISODES[index]
    text, final_url = fetch_text(episode["url"])
    playlist = parse_media_playlist(text, final_url)
    playlist["episode_index"] = index
    playlist["source_total"] = sum(
        segment["duration"] for segment in playlist["segments"]
    )
    return playlist


def build_cycle(playlists):
    flat_segments = []
    episode_ranges = []
    target_duration = 1

    for index, playlist in enumerate(playlists):
        start = len(flat_segments)
        for segment in playlist["segments"]:
            item = dict(segment)
            item["episode_index"] = index
            flat_segments.append(item)
        end = len(flat_segments)
        episode_ranges.append(
            {
                "start": start,
                "end": end,
                "source_total": playlist["source_total"],
                "schedule_total": float(EPISODES[index]["duration"]),
            }
        )
        target_duration = max(target_duration, playlist["target_duration"])

    if not flat_segments:
        raise RuntimeError("播放列表为空")

    return {
        "segments": flat_segments,
        "episode_ranges": episode_ranges,
        "target_duration": target_duration,
        "segment_count": len(flat_segments),
        "schedule_total": sum(float(item["duration"]) for item in EPISODES),
    }


def locate_segment(cycle, elapsed):
    """按固定时间轴找到当前分片和分片内偏移。"""
    schedule_total = cycle["schedule_total"]
    elapsed = elapsed % schedule_total
    schedule_cursor = 0.0

    for episode_range in cycle["episode_ranges"]:
        schedule_duration = episode_range["schedule_total"]
        if elapsed < schedule_cursor + schedule_duration:
            within = elapsed - schedule_cursor
            source_total = episode_range["source_total"]
            source_position = 0.0
            if schedule_duration > 0:
                source_position = within / schedule_duration * source_total

            source_cursor = 0.0
            for index in range(episode_range["start"], episode_range["end"]):
                segment = cycle["segments"][index]
                if source_position < source_cursor + segment["duration"]:
                    return index, max(0.0, source_position - source_cursor)
                source_cursor += segment["duration"]
            return episode_range["end"] - 1, 0.0
        schedule_cursor += schedule_duration

    return cycle["segment_count"] - 1, 0.0


def build_hls_manifest(cycle, anchor):
    now = time.time()
    elapsed_since_anchor = max(0.0, now - anchor)
    cycle_number = int(elapsed_since_anchor // cycle["schedule_total"])
    within_cycle = elapsed_since_anchor % cycle["schedule_total"]
    first_index, first_offset = locate_segment(cycle, within_cycle)
    segment_count = cycle["segment_count"]

    lines = [
        "#EXTM3U",
        "#EXT-X-VERSION:3",
        "#EXT-X-TARGETDURATION:{}".format(cycle["target_duration"]),
        "#EXT-X-MEDIA-SEQUENCE:{}".format(
            cycle_number * segment_count + first_index
        ),
        "#EXT-X-START:TIME-OFFSET={:.3f},PRECISE=YES".format(first_offset),
    ]

    emitted_duration = 0.0
    current_index = first_index
    previous_episode = None
    minimum_segments = 20
    emitted_segments = 0

    # 不写 EXT-X-ENDLIST，告诉播放器这是会持续刷新的直播式清单。
    # 每次刷新都会根据固定时间轴重新生成当前位置，最后一集后自然回到第一集。
    while emitted_duration < MANIFEST_WINDOW_SECONDS or emitted_segments < minimum_segments:
        segment = cycle["segments"][current_index]
        episode_index = segment["episode_index"]

        if previous_episode is not None and episode_index != previous_episode:
            lines.append("#EXT-X-DISCONTINUITY")
        previous_episode = episode_index

        for tag in segment["tags"]:
            lines.append(tag)
        lines.append("#EXTINF:{:.3f},".format(segment["duration"]))
        lines.append(segment["url"])

        emitted_duration += segment["duration"]
        emitted_segments += 1
        current_index = (current_index + 1) % segment_count

    return "\n".join(lines) + "\n"


def build_simple_m3u():
    """给不支持拼接 HLS 的播放器提供一个普通 M3U 备用入口。"""
    lines = ["#EXTM3U"]
    for episode in EPISODES:
        lines.append("#EXTINF:{},{}".format(episode["duration"], episode["title"]))
        lines.append(episode["url"])
    return "\n".join(lines) + "\n"


class Catalog(object):
    def __init__(self):
        self.lock = threading.RLock()
        self.cycle = None
        self.last_refresh = 0.0
        self.refreshing = False
        self.last_error = ""

    def refresh(self):
        with self.lock:
            if self.refreshing:
                return
            self.refreshing = True

        try:
            playlists = [None] * len(EPISODES)
            errors = []

            if ThreadPoolExecutor is None:
                for index in range(len(EPISODES)):
                    try:
                        playlists[index] = load_episode(index)
                    except Exception as exc:
                        errors.append("{}: {}".format(EPISODES[index]["title"], exc))
            else:
                with ThreadPoolExecutor(max_workers=4) as executor:
                    futures = {
                        executor.submit(load_episode, index): index
                        for index in range(len(EPISODES))
                    }
                    for future in as_completed(futures):
                        index = futures[future]
                        try:
                            playlists[index] = future.result()
                        except Exception as exc:
                            errors.append("{}: {}".format(EPISODES[index]["title"], exc))

            if errors:
                raise RuntimeError("；".join(errors))

            cycle = build_cycle(playlists)
            with self.lock:
                self.cycle = cycle
                self.last_refresh = time.time()
                self.last_error = ""
        except Exception as exc:
            with self.lock:
                self.last_error = str(exc)
        finally:
            with self.lock:
                self.refreshing = False

    def get(self):
        with self.lock:
            cycle = self.cycle
            stale = time.time() - self.last_refresh > CATALOG_REFRESH_SECONDS
            if stale and not self.refreshing:
                thread = threading.Thread(target=self.refresh)
                thread.daemon = True
                thread.start()
            return cycle

    def status(self):
        with self.lock:
            return {
                "ready": self.cycle is not None,
                "refreshing": self.refreshing,
                "last_refresh": self.last_refresh,
                "error": self.last_error,
                "episodes": len(EPISODES),
            }


class ThreadingHTTPServer(socketserver.ThreadingMixIn, http.server.HTTPServer):
    daemon_threads = True
    allow_reuse_address = True


class Handler(http.server.BaseHTTPRequestHandler):
    server_version = "aTV-Daranfang/1.0"

    def log_message(self, format_string, *args):
        # 保留最少日志，避免 aTV 控制台被大量刷新请求刷屏。
        if self.path.startswith("/status") or self.path == "/":
            sys.stderr.write("[aTV] " + format_string % args + "\n")

    def _send(self, body, content_type, status=200):
        if isinstance(body, str):
            body = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        path = urlparse(self.path).path
        catalog = self.server.catalog

        if path == "/" or path == "/status.json":
            status = catalog.status()
            status["hls_url"] = self.server.hls_url
            status["m3u_url"] = self.server.m3u_url
            self._send(
                json.dumps(status, ensure_ascii=False, indent=2),
                "application/json; charset=utf-8",
            )
            return

        if path == "/daranfang.m3u":
            self._send(build_simple_m3u(), "audio/x-mpegurl; charset=utf-8")
            return

        if path == "/daranfang.m3u8":
            cycle = catalog.get()
            if cycle is None:
                message = "playlist not ready: {}\n".format(catalog.status()["error"])
                self._send(message, "text/plain; charset=utf-8", status=503)
                return
            manifest = build_hls_manifest(cycle, self.server.anchor)
            self._send(manifest, "application/vnd.apple.mpegurl; charset=utf-8")
            return

        self._send("not found\n", "text/plain; charset=utf-8", status=404)


def main():
    args = parse_args()
    anchor = load_anchor(args.reset)
    catalog = Catalog()

    print("正在读取 24 个 HLS 清单，请稍候……")
    catalog.refresh()
    if catalog.cycle is None:
        print("警告：远端清单暂时无法读取。服务器仍会启动，并会自动重试。")
        print(catalog.last_error, file=sys.stderr)

    try:
        server = ThreadingHTTPServer((args.host, args.port), Handler)
    except OSError as exc:
        print(
            "无法监听 {}:{}：{}。请确认端口未被占用。".format(
                args.host, args.port, exc
            ),
            file=sys.stderr,
        )
        return 2

    server.catalog = catalog
    server.anchor = anchor
    display_host = "127.0.0.1" if args.host in ("0.0.0.0", "::") else args.host
    server.hls_url = "http://{}:{}/daranfang.m3u8".format(display_host, args.port)
    server.m3u_url = "http://{}:{}/daranfang.m3u".format(display_host, args.port)

    print("")
    print("aTV 轮播源已启动：")
    print(server.hls_url)
    print("")
    print("备用普通 M3U 地址：")
    print(server.m3u_url)
    print("")
    print("状态地址：")
    print("http://{}:{}/status.json".format(display_host, args.port))
    print("按 Ctrl+C 停止服务。")

    try:
        server.serve_forever(poll_interval=0.5)
    except KeyboardInterrupt:
        print("\n服务已停止。")
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
