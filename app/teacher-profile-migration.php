<?php
declare(strict_types=1);
// Additive, repeatable teacher profile migration from the reviewed directory snapshot.
// No booking, payment or admin account tables are modified.
function migrateTeacherProfileDirectory(): array {
    $pdo=db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS teachers (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_profiles (
      teacher_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      summary TEXT NULL,
      career MEDIUMTEXT NULL,
      qualifications_text MEDIUMTEXT NULL,
      training MEDIUMTEXT NULL,
      activity_area TEXT NULL,
      message MEDIUMTEXT NULL,
      photo_path VARCHAR(255) NULL,
      photo_source_url TEXT NULL,
      source_url TEXT NULL,
      source_checked_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_teacher_profiles_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS qualification_master (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(250) NOT NULL,
      category VARCHAR(80) NOT NULL DEFAULT "未分類",
      UNIQUE KEY uq_qualification_name(name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $pdo->exec('CREATE TABLE IF NOT EXISTS teacher_qualifications (
      teacher_id BIGINT UNSIGNED NOT NULL,
      qualification_id BIGINT UNSIGNED NOT NULL,
      note VARCHAR(255) NOT NULL DEFAULT "",
      PRIMARY KEY(teacher_id,qualification_id),
      CONSTRAINT fk_tq_teacher FOREIGN KEY(teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
      CONSTRAINT fk_tq_qualification FOREIGN KEY(qualification_id) REFERENCES qualification_master(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $rows=require __DIR__.'/teacher-directory-summaries.php';
    $normalize=static fn(string $name):string=>preg_replace('/[\\s\\x{3000}]+/u','',trim($name));
    $pdo->beginTransaction();
    try {
      $existing=$pdo->query('SELECT id,name FROM teachers')->fetchAll(PDO::FETCH_ASSOC);
      $ids=[];
      foreach($existing as $row)$ids[$normalize((string)$row['name'])]=(int)$row['id'];
      $insertTeacher=$pdo->prepare('INSERT INTO teachers(name,active) VALUES(?,1)');
      $insertProfile=$pdo->prepare('INSERT INTO teacher_profiles(teacher_id,summary,training,source_url,source_checked_at)
        VALUES(?,?,?, ?, NOW()) ON DUPLICATE KEY UPDATE
        summary=IF(COALESCE(summary,"")="",VALUES(summary),summary),
        training=IF(COALESCE(training,"")="",VALUES(training),training),
        source_url=IF(COALESCE(source_url,"")="",VALUES(source_url),source_url),
        source_checked_at=NOW()');
      $insertQualification=$pdo->prepare('INSERT INTO qualification_master(name,category)
         VALUES(?,"未分類") ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
      $attach=$pdo->prepare('INSERT IGNORE INTO teacher_qualifications(teacher_id,qualification_id) VALUES(?,?)');
      $count=['teachers'=>0,'summaries'=>0,'qualification_links'=>0,'created_teachers'=>0,'missing_summaries'=>[]];
      foreach($rows as [$name,$summary,$qualificationList,$training]){
        $key=$normalize($name);$id=$ids[$key]??0;
        if(!$id){
          $insertTeacher->execute([$name]);$id=(int)$pdo->lastInsertId();
          $ids[$key]=$id;$count['created_teachers']++;
        }
        $insertProfile->execute([$id,$summary,$training,'https://hanamaru-souken.com/member_list/']);
        $count['teachers']++;
        if(trim($summary)!=='')$count['summaries']++;else $count['missing_summaries'][]=$name;
        foreach(array_filter(array_map('trim',explode('|',$qualificationList))) as $qual){
          $insertQualification->execute([$qual]);
          $attach->execute([$id,(int)$pdo->lastInsertId()]);
          $count['qualification_links']++;
        }
      }
      $pdo->commit();
      $count['profiles_in_db']=(int)$pdo->query("SELECT COUNT(*) FROM teacher_profiles WHERE COALESCE(summary,'')<>'' OR COALESCE(career,'')<>'' OR COALESCE(photo_path,'')<>''")->fetchColumn();
      $count['photos_in_db']=(int)$pdo->query("SELECT COUNT(*) FROM teacher_profiles WHERE COALESCE(photo_path,'')<>''")->fetchColumn();
      $count['qualification_master_count']=(int)$pdo->query('SELECT COUNT(*) FROM qualification_master')->fetchColumn();
      $count['qualification_links_in_db']=(int)$pdo->query('SELECT COUNT(*) FROM teacher_qualifications')->fetchColumn();
      return $count;
    }catch(Throwable $e){
      if($pdo->inTransaction())$pdo->rollBack();
      throw $e;
    }
}
