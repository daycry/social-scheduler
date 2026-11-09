"""Bounded local font parsing/normalization. Arguments are server-generated paths, never URLs."""

import ctypes
import io
import json
import os
import re
import struct
import sys


def limit_memory(limit=512 * 1024 * 1024, cpu_seconds=18):
    if os.name != "nt":
        import resource

        resource.setrlimit(resource.RLIMIT_AS, (limit, limit))
        resource.setrlimit(resource.RLIMIT_CPU, (cpu_seconds, cpu_seconds))
        return None

    from ctypes import wintypes

    class BasicLimits(ctypes.Structure):
        _fields_ = [
            ("process_time", ctypes.c_longlong), ("job_time", ctypes.c_longlong),
            ("flags", wintypes.DWORD), ("minimum_working_set", ctypes.c_size_t),
            ("maximum_working_set", ctypes.c_size_t), ("active_processes", wintypes.DWORD),
            ("affinity", ctypes.c_size_t), ("priority", wintypes.DWORD), ("scheduling", wintypes.DWORD),
        ]

    class IoCounters(ctypes.Structure):
        _fields_ = [(name, ctypes.c_ulonglong) for name in ("read_ops", "write_ops", "other_ops", "read_bytes", "write_bytes", "other_bytes")]

    class ExtendedLimits(ctypes.Structure):
        _fields_ = [
            ("basic", BasicLimits), ("io", IoCounters), ("process_memory", ctypes.c_size_t),
            ("job_memory", ctypes.c_size_t), ("peak_process_memory", ctypes.c_size_t), ("peak_job_memory", ctypes.c_size_t),
        ]

    kernel = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel.CreateJobObjectW.restype = wintypes.HANDLE
    kernel.CreateJobObjectW.argtypes = [ctypes.c_void_p, wintypes.LPCWSTR]
    kernel.GetCurrentProcess.restype = wintypes.HANDLE
    kernel.SetInformationJobObject.argtypes = [wintypes.HANDLE, ctypes.c_int, ctypes.c_void_p, wintypes.DWORD]
    kernel.AssignProcessToJobObject.argtypes = [wintypes.HANDLE, wintypes.HANDLE]
    job = kernel.CreateJobObjectW(None, None)
    bounds = ExtendedLimits()
    bounds.basic.flags = 0x100  # JOB_OBJECT_LIMIT_PROCESS_MEMORY
    bounds.process_memory = limit
    if not job or not kernel.SetInformationJobObject(job, 9, ctypes.byref(bounds), ctypes.sizeof(bounds)) or not kernel.AssignProcessToJobObject(job, kernel.GetCurrentProcess()):
        raise RuntimeError("Cannot bound the private Studio worker")
    return job  # Retain the handle while parsing.


def preflight(data, maximum_decoded):
    signature = data[:4]
    if signature in (b"\0\1\0\0", b"OTTO", b"true"):
        if len(data) < 12:
            raise ValueError("Invalid sfnt header")
        count = struct.unpack_from(">H", data, 4)[0]
        if not 1 <= count <= 128 or 12 + count * 16 > len(data):
            raise ValueError("Invalid sfnt tables")
        total = 12 + count * 16
        spans = []
        tags = set()
        for index in range(count):
            tag, _, offset, size = struct.unpack_from(">4sIII", data, 12 + index * 16)
            if tag in tags or offset + size > len(data) or (size and offset < 12 + count * 16):
                raise ValueError("Invalid sfnt table range")
            tags.add(tag)
            total += size
            if size:
                spans.append((offset, offset + size))
        spans.sort()
        if any(right > next_left for (_, right), (next_left, _) in zip(spans, spans[1:])):
            raise ValueError("Overlapping sfnt tables")
        if total > maximum_decoded:
            raise ValueError("Decoded font too large")
    elif signature in (b"wOFF", b"wOF2"):
        minimum = 44 if signature == b"wOFF" else 48
        if len(data) < minimum:
            raise ValueError("Invalid WOFF header")
        declared_size = struct.unpack_from(">I", data, 8)[0]
        tables = struct.unpack_from(">H", data, 12)[0]
        decoded_size = struct.unpack_from(">I", data, 16)[0]
        if declared_size != len(data) or not 1 <= tables <= 128 or not 12 <= decoded_size <= maximum_decoded:
            raise ValueError("Invalid WOFF sizes")
        if signature == b"wOFF":
            if 44 + tables * 20 > len(data):
                raise ValueError("Invalid WOFF directory")
            decoded_total = 12 + tables * 16
            spans, tags = [], set()
            for index in range(tables):
                tag, offset, compressed, original, _ = struct.unpack_from(">4sIIII", data, 44 + index * 20)
                decoded_total += (original + 3) & ~3
                if tag in tags or offset < 44 + tables * 20 or offset + compressed > len(data) or compressed > original or decoded_total > maximum_decoded:
                    raise ValueError("Invalid WOFF table")
                tags.add(tag)
                if compressed:
                    spans.append((offset, offset + compressed))
            spans.sort()
            if any(right > next_left for (_, right), (next_left, _) in zip(spans, spans[1:])):
                raise ValueError("Overlapping WOFF tables")
    else:
        raise ValueError("Unsupported font format")


def main():
    if len(sys.argv) != 5:
        raise ValueError("Invalid arguments")
    maximum_input, maximum_decoded = int(sys.argv[3]), int(sys.argv[4])
    if not 1 <= maximum_input <= 8 * 1024 * 1024 or not 1 <= maximum_decoded <= 32 * 1024 * 1024:
        raise ValueError("Invalid budgets")
    with open(sys.argv[1], "rb") as source:
        data = source.read(maximum_input + 1)
    if len(data) > maximum_input:
        raise ValueError("Font too large")
    preflight(data, maximum_decoded)
    try:
        memory_job = limit_memory()
        from fontTools.ttLib import TTFont
    except (ImportError, RuntimeError, OSError):
        print(json.dumps({"ok": False, "error": "runtime_unavailable"}))
        return 2
    font = TTFont(io.BytesIO(data), lazy=False, checkChecksums=2, recalcBBoxes=False)
    try:
        required = {"head", "hhea", "hmtx", "maxp", "cmap", "name"}
        if not required.issubset(set(font.keys())) or not font.getBestCmap() or not 1 <= font["maxp"].numGlyphs <= 65535:
            raise ValueError("Incomplete font")
        font.ensureDecompiled()
        decoded_total = 12 + 16 * len(font.reader.tables)
        for tag in font.reader.tables:
            decoded_total += (len(font.getTableData(tag)) + 3) & ~3
            if decoded_total > maximum_decoded:
                raise ValueError("Decoded font too large")
        family = font["name"].getDebugName(16) or font["name"].getDebugName(1) or "Fuente privada"
        family = re.sub(r"[^\w .,_-]", " ", family, flags=re.UNICODE).strip(" .,_-")[:100]
        if not family or family.lower().startswith("studiofont_"):
            family = "Fuente privada"
        weight = getattr(font.get("OS/2"), "usWeightClass", 400)
        if "fvar" in font:
            weight = next((axis.defaultValue for axis in font["fvar"].axes if axis.axisTag == "wght"), weight)
        weight = max(100, min(900, int(weight)))
        italic = bool(getattr(font.get("OS/2"), "fsSelection", 0) & 1) or bool(getattr(font.get("post"), "italicAngle", 0))
        font.flavor = "woff2"
        output = io.BytesIO()
        font.save(output, reorderTables=True)
        normalized = output.getvalue()
        if not 48 <= len(normalized) <= maximum_input or normalized[:4] != b"wOF2":
            raise ValueError("Invalid normalized size")
        with open(sys.argv[2], "wb") as destination:
            destination.write(normalized)
        print(json.dumps({"ok": True, "family": family, "style": "italic" if italic else "normal", "weight": weight}, ensure_ascii=False))
        return 0
    finally:
        font.close()


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (Exception, MemoryError):
        print(json.dumps({"ok": False, "error": "invalid_font"}))
        sys.exit(1)
