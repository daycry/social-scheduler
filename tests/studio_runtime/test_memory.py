import json
import os
import subprocess
import sys
import unittest


class WorkerMemoryTest(unittest.TestCase):
    def test_worker_main_applies_a_real_nine_gib_os_memory_limit(self):
        child = r'''
import ctypes, io, json, os, runpy, sys
def observe(_):
    if os.name == 'nt':
        from ctypes import wintypes
        import struct
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.QueryInformationJobObject.argtypes=[wintypes.HANDLE,ctypes.c_int,ctypes.c_void_p,wintypes.DWORD,ctypes.c_void_p]
        bounds=ctypes.create_string_buffer(144)
        ok=kernel.QueryInformationJobObject(None,9,bounds,144,None)
        memory=struct.unpack_from('<Q',bounds.raw,112)[0] if ok else 0
    else:
        import resource
        memory=resource.getrlimit(resource.RLIMIT_DATA)[0]  # resident-style bound: RLIMIT_AS made onnxruntime fail with bad_alloc on Linux
    return {'ok':True,'observed_memory_limit':memory}
namespace=runpy.run_path('scripts/studio/process-image.py')
namespace['main'].__globals__['process']=observe
sys.stdin=io.StringIO('{}')
raise SystemExit(namespace['main']())
'''
        result = subprocess.run([sys.executable, '-c', child], capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['observed_memory_limit'], 9 * 1024**3)


    @unittest.skipIf(os.name == 'nt', 'RLIMIT_DATA is POSIX only; Windows enforces the same bound with a Job Object (covered by the test above)')
    def test_resident_limit_really_stops_a_process_that_exceeds_it_but_not_large_unused_reservations(self):
        child = r'''
import json, mmap, runpy, sys
limits = runpy.run_path('scripts/studio/font_probe.py', run_name='probe')
limits['limit_memory'](256 * 1024 * 1024, 60, resident=True)
reserved = mmap.mmap(-1, 2 * 1024**3, flags=mmap.MAP_PRIVATE | mmap.MAP_ANONYMOUS, prot=0)  # PROT_NONE  # untouched virtual range, like ORT/NumPy arenas
outcome = {'reserved': True}
try:
    block = bytearray(512 * 1024 * 1024)
    for offset in range(0, len(block), 4096):
        block[offset] = 1
    outcome['exceeded'] = 'allowed'
except MemoryError:
    outcome['exceeded'] = 'stopped'
print(json.dumps(outcome))
'''
        result = subprocess.run([sys.executable, '-c', child], capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {'reserved': True, 'exceeded': 'stopped'})


if __name__ == '__main__':
    unittest.main()
