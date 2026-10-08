Here's your my.ini with the changes for a 4 GB PC with 1 million products. Replace the whole file with this, then restart MySQL in Laragon (Stop → Start All):


[client]
#password=your_password
port=3306
socket=/tmp/mysql.sock

[mysqld]
port=3306
socket=/tmp/mysql.sock

# --- InnoDB (all POS tables are InnoDB) ---
innodb_buffer_pool_size=1G
innodb_redo_log_capacity=256M
innodb_flush_log_at_trx_commit=2
innodb_flush_method=normal
innodb_file_per_table=1
innodb_ft_enable_stopword=0

# --- MyISAM is not used by the POS: keep this small to save RAM ---
key_buffer_size=16M
myisam_sort_buffer_size=16M

max_allowed_packet=512M
table_open_cache=1024
sort_buffer_size=1M
read_buffer_size=1M
read_rnd_buffer_size=1M
join_buffer_size=1M
tmp_table_size=64M
max_heap_table_size=64M
thread_cache_size=16
max_connections=50

secure-file-priv=""
explicit_defaults_for_timestamp=1
datadir= "D:/laragon/data/mysql-8"
default_authentication_plugin=mysql_native_password

[mysqldump]
quick
max_allowed_packet=512M
What changed, and why:

Setting	Before	Now	Why
innodb_buffer_pool_size	128M (default)	1G	Products and search indexes stay in memory instead of being read from disk on every search
innodb_redo_log_capacity	100M (default)	256M	Smoother saving of sales on a busy day
innodb_flush_log_at_trx_commit	1	2	Saving sales is much faster. If the power cuts, at most the last second of work is lost
innodb_ft_enable_stopword	on	0	Matches how the search index was built, so words like "a" or "in" don't hide products
key_buffer_size	256M	16M	That memory was for MyISAM, which the POS doesn't use; freed for InnoDB
max_connections	151	50	Plenty for one shop, and less memory reserved
Two cautions:

If MySQL doesn't start after the change, the likely culprit is innodb_redo_log_capacity (it only exists in MySQL 8.0.30 and newer). Delete that one line and start again.
If the PC gets 8 GB of RAM later, change innodb_buffer_pool_size=1G to 3G.