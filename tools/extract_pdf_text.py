import sys
from pathlib import Path

try:
    from pypdf import PdfReader
except Exception:
    try:
        from PyPDF2 import PdfReader
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        sys.exit(1)

if len(sys.argv) < 2:
    sys.exit(1)

file_path = Path(sys.argv[1])
if not file_path.exists():
    sys.exit(1)

reader = PdfReader(str(file_path))
texts = []
for page in reader.pages:
    text = page.extract_text() or ''
    if text:
        texts.append(text)

sys.stdout.write('\n'.join(texts))
