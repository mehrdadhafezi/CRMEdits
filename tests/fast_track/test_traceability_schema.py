"""Allow only the observed redundant MySQL charset spelling, never schema drift."""
import importlib.util,pathlib,unittest
p=pathlib.Path(__file__).resolve().parents[1]/'payments/traceability_schema.py'
spec=importlib.util.spec_from_file_location('trace242_schema',p);schema=importlib.util.module_from_spec(spec);spec.loader.exec_module(schema)

class SchemaProofTests(unittest.TestCase):
 def canonical(self,value):return schema.canonical_dump(value.encode())
 def test_observed_mysql_restore_spelling_is_equivalent(self):
  for column in ['`hook` varchar(191)','`status` varchar(20)','`schedule` longtext']:
   implicit='  '+column+' COLLATE utf8mb4_unicode_520_ci NOT NULL,\n'
   explicit=implicit.replace('COLLATE','CHARACTER SET utf8mb4 COLLATE')
   self.assertEqual(self.canonical(implicit),self.canonical(explicit))

 def test_real_charset_collation_default_width_nullable_and_index_changes_survive(self):
  original='  `value` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci NOT NULL DEFAULT \'x\',\n  KEY `by_value` (`value`(20))\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n'
  for old,new in [('utf8mb4_unicode_520_ci','utf8mb4_bin'),('CHARACTER SET utf8mb4','CHARACTER SET latin1'),('varchar(191)','varchar(200)'),('NOT NULL','NULL'),("DEFAULT 'x'","DEFAULT 'y'"),('(`value`(20))','(`value`(21))'),('DEFAULT CHARSET=utf8mb4','DEFAULT CHARSET=latin1'),('ENGINE=InnoDB','ENGINE=MyISAM')]:
   with self.subTest(change=new):self.assertNotEqual(self.canonical(original),self.canonical(original.replace(old,new)))

 def test_charset_without_matching_collation_is_never_removed(self):
  for tail in ['CHARACTER SET utf8mb4','CHARACTER SET latin1 COLLATE utf8mb4_bin','CHARACTER SET utf8mb4 COLLATE utf8mb3_bin']:
   line='  `value` varchar(20) '+tail+';\n';self.assertEqual(self.canonical(line),line.encode())

 def test_quoted_default_enum_comment_and_identifiers_remain_exact(self):
  token='CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci'
  for line in ["  `value` varchar(191) DEFAULT '"+token+"';\n", "  `value` enum('"+token+"','x');\n", "  `value` varchar(191) COMMENT 'escaped \\\' "+token+"';\n", "  `value` varchar(191) COMMENT 'doubled '' "+token+"';\n",'  `'+token+'` varchar(191);\n','  `value` varchar(191) DEFAULT "'+token+'";\n']:
   with self.subTest(line=line):self.assertEqual(self.canonical(line),line.encode())
  multiline="  `value` text DEFAULT 'first line\n  `still_a_literal` "+token+"\nlast line';\n"
  self.assertEqual(self.canonical(multiline),multiline.encode())

 def test_non_column_lines_and_arbitrary_backup_bytes_are_not_normalized(self):
  for line in ['CREATE TABLE `x` (CHARACTER SET utf8mb4 COLLATE utf8mb4_bin);\n','/*!80000 CHARACTER SET utf8mb4 COLLATE utf8mb4_bin */\n',"INSERT INTO `x` VALUES ('CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');\n"]:self.assertEqual(self.canonical(line),line.encode())

 def test_idempotence_and_column_order_are_preserved(self):
  line='  `a` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,\n  `b` int NOT NULL\n';value=self.canonical(line)
  self.assertEqual(schema.canonical_dump(value),value);self.assertNotEqual(value,self.canonical(''.join(reversed(line.splitlines(keepends=True)))))

if __name__=='__main__':unittest.main()
