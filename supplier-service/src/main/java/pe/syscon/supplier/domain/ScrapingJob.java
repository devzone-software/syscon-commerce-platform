package pe.syscon.supplier.domain;
import jakarta.persistence.*;
import java.time.Instant;
import java.util.UUID;
@Entity public class ScrapingJob {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @ManyToOne(optional=false) public Supplier supplier;
 public Instant startedAt;
 public Instant finishedAt;
 @Column(nullable=false) public String status;
 public int imported;
 protected ScrapingJob() {} public ScrapingJob(Supplier supplier) {this.supplier=supplier;this.status="RUNNING";this.startedAt=Instant.now();}
}
