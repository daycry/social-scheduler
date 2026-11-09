import json
import subprocess
import sys
import unittest


class WorkerMemoryTest(unittest.TestCase):
    def test_worker_main_applies_a_real_six_gib_os_memory_limit(self):
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
        memory=resource.getrlimit(resource.RLIMIT_AS)[0]
    return {'ok':True,'observed_memory_limit':memory}
namespace=runpy.run_path('scripts/studio/process-image.py')
namespace['main'].__globals__['process']=observe
sys.stdin=io.StringIO('{}')
raise SystemExit(namespace['main']())
'''
        result = subprocess.run([sys.executable, '-c', child], capture_output=True, text=True, timeout=20)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout)['observed_memory_limit'], 6 * 1024**3)


if __name__ == '__main__':
    unittest.main()
