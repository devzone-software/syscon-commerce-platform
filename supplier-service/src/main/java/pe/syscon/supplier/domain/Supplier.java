package pe.syscon.supplier.domain;
import jakarta.persistence.*;
import java.util.UUID;
@Entity public class Supplier {
 @Id @GeneratedValue(strategy=GenerationType.UUID) public UUID id;
 @Column(nullable=false,unique=true) public String name;
 @Column(nullable=false) public String feedUrl;
 public boolean active=true;
 public int minIntervalMinutes=60;
 protected Supplier() {} public Supplier(String name,String feedUrl,int minutes) {this.name=name;this.feedUrl=feedUrl;this.minIntervalMinutes=minutes;}
}
