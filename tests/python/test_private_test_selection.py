import pathlib
import re
import subprocess
import tempfile
import unittest


class PrivateSelectionTest(unittest.TestCase):
    def test_named_and_numeric_datasets_select_exactly_one_runtime_case(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            xml = '<testSuite><testMethod id="Example::test_case#partial"/><testMethod id="Example::test_case#completed"/><testMethod id="Example::test_case#0"/><testMethod id="Example::test_plain"/></testSuite>'
            for cohort in ('rollback', 'committed'):
                (root / f'selection-{cohort}.xml').write_text(xml)
            subprocess.run(['python3', str(pathlib.Path(__file__).resolve().parents[2] / 'scripts/private-test-selection.py'), 'enumerate', directory], check=True)
            names = ['Example::test_case with data set "partial"', 'Example::test_case with data set "completed"', 'Example::test_case with data set #0', 'Example::test_plain']
            filters = (root / 'selection-committed.filters').read_text().splitlines()
            for index, expression in enumerate(filters):
                self.assertEqual([index], [i for i, name in enumerate(names) if re.match(expression[1:-1], name)])


if __name__ == '__main__':
    unittest.main()
