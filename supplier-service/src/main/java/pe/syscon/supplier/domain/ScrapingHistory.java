package pe.syscon.supplier.domain;
import jakarta.persistence.*;
import java.time.Instant;
import java.util.UUID;
@Entity public class ScrapingHistory {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @ManyToOne(optional=false) public ScrapingJob job;
 public Instant occurredAt=Instant.now();
 @Column(nullable=false) public String level;
 @Column(length=1000) public String message;
 protected ScrapingHistory() {} public ScrapingHistory(ScrapingJob job,String level,String message) {this.job=job;this.level=level;this.message=message;}
}
