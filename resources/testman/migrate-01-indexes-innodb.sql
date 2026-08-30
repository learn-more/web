-- Testman migration 01: timeline indexes and InnoDB conversion
--
-- Adds the indexes that date filtering and the run browser need, and converts the two
-- big tables to InnoDB. Both happen in a single ALTER per table, because either change
-- rebuilds the whole table anyway.
--
-- ix_runs_ts       every search filters finished = 1 and then orders/pages by id;
--                  the timestamp in the middle makes the date filter an index range.
-- ix_runs_src_plat covers "this builder on this platform, newest first", which is what
--                  the landing view and the suite history page ask for.
--
-- KEY (revision) already serves the LIKE 'abc1234%' prefix search at the full 40 chars,
-- so no extra index is needed for the hash filter.
--
-- These tables are MyISAM, so the rebuild holds a table-level write lock and submitting
-- builders block until it finishes. Run it in a maintenance window, and measure first to
-- know how long that window has to be:
--
--   SELECT table_name, engine, table_rows,
--          ROUND(data_length /1024/1024) AS data_mb,
--          ROUND(index_length/1024/1024) AS index_mb
--   FROM information_schema.tables
--   WHERE table_schema = 'testman'
--   ORDER BY data_length DESC;
--
-- winetest_logs is deliberately left alone. It is by far the largest table (one
-- mediumblob per suite per run) and is only ever read by primary key, so converting it
-- would dominate the window without buying anything.

ALTER TABLE `winetest_runs`
  ENGINE=InnoDB,
  ADD KEY `ix_runs_ts` (`finished`,`timestamp`,`id`),
  ADD KEY `ix_runs_src_plat` (`source_id`,`platform`,`timestamp`);

ALTER TABLE `winetest_results`
  ENGINE=InnoDB;
